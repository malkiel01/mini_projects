// js/pwa-notifications-compact.js - גרסה קומפקטית למובייל

// תיקיית האפליקציה - תיקיית האב של js/, בלי נתיב קשיח.
const PWA_BASE = new URL('..', document.currentScript.src).pathname;

class PWANotificationManager {
    constructor() {
        this.registration = null;
        this.isSupported = 'Notification' in window && 'serviceWorker' in navigator;
        this.init();
    }
    
    async init() {
        if (!this.isSupported) return;
        
        try {
            this.registration = await navigator.serviceWorker.register(PWA_BASE + 'service-worker.js', {
                scope: PWA_BASE
            });
            
            // בדוק אם זה הביקור הראשון
            const isFirstVisit = localStorage.getItem('pwa-first-visit') === null;
            if (isFirstVisit) {
                localStorage.setItem('pwa-first-visit', 'true');
                setTimeout(() => this.handlePermissionRequest(), 2000);
            }
        } catch (error) {
            console.error('Service Worker registration failed:', error);
        }
    }
    
    async handlePermissionRequest() {
        if (Notification.permission === 'default') {
            // בדוק אם כבר דחה בעבר
            const dismissed = localStorage.getItem('notifications-dismissed');
            const dismissedTime = localStorage.getItem('notifications-dismissed-time');
            
            if (dismissed && dismissedTime) {
                const daysPassed = (Date.now() - parseInt(dismissedTime)) / (1000 * 60 * 60 * 24);
                if (daysPassed < 7) return;
            }
            
            this.showCompactDialog();
        }
    }
    
    showCompactDialog() {
        const modal = document.createElement('div');
        modal.className = 'pwa-notification-modal';
        modal.innerHTML = `
            <div class="pwa-modal-backdrop"></div>
            <div class="pwa-modal-card">
                <!-- כותרת קצרה -->
                <div class="pwa-modal-header">
                    <div class="pwa-bell-icon">🔔</div>
                    <h3>קבל התראות בזמן אמת</h3>
                </div>
                
                <!-- תוכן קומפקטי - רק 3 נקודות -->
                <div class="pwa-modal-features">
                    <div class="pwa-feature-item">
                        <span class="feature-emoji">👥</span>
                        <span>הזמנות לקבוצות חדשות</span>
                    </div>
                    <div class="pwa-feature-item">
                        <span class="feature-emoji">🛒</span>
                        <span>עדכונים על קניות חדשות</span>
                    </div>
                    <div class="pwa-feature-item">
                        <span class="feature-emoji">💰</span>
                        <span>סיכום חישובים מעודכן</span>
                    </div>
                </div>
                
                <!-- כפתורים -->
                <div class="pwa-modal-buttons">
                    <button class="pwa-btn-allow" id="allow-btn">
                        אפשר התראות
                    </button>
                    <button class="pwa-btn-later" id="later-btn">
                        מאוחר יותר
                    </button>
                </div>
            </div>
        `;
        
        // הוסף סגנונות
        this.addCompactStyles();
        
        document.body.appendChild(modal);
        
        // הצג עם אנימציה
        requestAnimationFrame(() => {
            modal.classList.add('show');
        });
        
        // מאזינים
        modal.querySelector('#allow-btn').onclick = async () => {
            try {
                const permission = await Notification.requestPermission();
                
                if (permission === 'granted') {
                    this.showToast('התראות הופעלו! 🎉', 'success');
                    setTimeout(() => this.showWelcomeNotification(), 1500);
                } else {
                    localStorage.setItem('notifications-dismissed', 'true');
                    localStorage.setItem('notifications-dismissed-time', Date.now().toString());
                }
                
                this.closeModal(modal);
            } catch (error) {
                console.error('Error requesting permission:', error);
                this.closeModal(modal);
            }
        };
        
        modal.querySelector('#later-btn').onclick = () => {
            localStorage.setItem('notifications-dismissed', 'true');
            localStorage.setItem('notifications-dismissed-time', Date.now().toString());
            this.closeModal(modal);
        };
        
        // סגירה בלחיצה על הרקע
        modal.querySelector('.pwa-modal-backdrop').onclick = () => {
            this.closeModal(modal);
        };
    }
    
    closeModal(modal) {
        modal.classList.remove('show');
        setTimeout(() => modal.remove(), 300);
    }
    
    async showWelcomeNotification() {
        if (Notification.permission === 'granted') {
            try {
                new Notification('ברוכים הבאים! 👋', {
                    body: 'התראות הופעלו בהצלחה',
                    icon: PWA_BASE + 'images/icons/android/android-launchericon-192-192.png',
                    tag: 'welcome',
                    dir: 'rtl',
                    lang: 'he'
                });
                
                localStorage.setItem('welcome-notification-sent', 'true');
            } catch (error) {
                console.error('Error showing notification:', error);
            }
        }
    }
    
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `pwa-toast pwa-toast-${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        requestAnimationFrame(() => {
            toast.classList.add('show');
        });
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    addCompactStyles() {
        if (document.getElementById('pwa-notification-styles')) return;
        
        const style = document.createElement('style');
        style.id = 'pwa-notification-styles';
        style.textContent = `
            /* Modal Container */
            .pwa-notification-modal {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                z-index: 10001;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }
            
            .pwa-notification-modal.show {
                opacity: 1;
                visibility: visible;
            }
            
            /* Backdrop */
            .pwa-modal-backdrop {
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0, 0, 0, 0.6);
                backdrop-filter: blur(3px);
            }
            
            /* Modal Card - קומפקטי! */
            .pwa-modal-card {
                position: relative;
                background: white;
                border-radius: 20px;
                padding: 20px;
                max-width: 340px;
                width: 90%;
                max-height: 420px; /* הגדלתי קצת */
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
                transform: scale(0.9) translateY(30px);
                transition: transform 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
                overflow-y: auto; /* אפשר גלילה אם צריך */
            }
            
            .pwa-notification-modal.show .pwa-modal-card {
                transform: scale(1) translateY(0);
            }
            
            /* Header - קצר וקומפקטי */
            .pwa-modal-header {
                text-align: center;
                margin-bottom: 20px;
            }
            
            .pwa-bell-icon {
                font-size: 48px;
                margin-bottom: 10px;
                animation: bellRing 2s ease-in-out infinite;
            }
            
            @keyframes bellRing {
                0%, 100% { transform: rotate(0deg); }
                10%, 30% { transform: rotate(10deg); }
                20%, 40% { transform: rotate(-10deg); }
            }
            
            .pwa-modal-header h3 {
                font-size: 20px;
                color: #333;
                margin: 0;
                font-weight: 600;
            }
            
            /* Features - רשימה פשוטה */
            .pwa-modal-features {
                margin-bottom: 20px;
            }
            
            .pwa-feature-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 10px 12px;
                background: #f8f9ff;
                border-radius: 10px;
                margin-bottom: 8px;
                font-size: 14px;
                color: #333;
            }
            
            .feature-emoji {
                font-size: 20px;
            }
            
            /* Buttons */
            .pwa-modal-buttons {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-top: 15px;
            }
            
            .pwa-btn-allow {
                width: 100%;
                background: linear-gradient(135deg, #667eea, #764ba2);
                color: white;
                border: none;
                padding: 14px 20px;
                border-radius: 12px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .pwa-btn-allow:active {
                transform: scale(0.98);
            }
            
            .pwa-btn-later {
                width: 100%;
                padding: 12px 20px;
                background: transparent;
                color: #999;
                border: 1px solid #e0e0e0;
                border-radius: 12px;
                font-size: 14px;
                cursor: pointer;
                transition: all 0.3s;
            }
            
            .pwa-btn-later:hover,
            .pwa-btn-later:active {
                background: #f8f8f8;
                border-color: #d0d0d0;
            }
            
            /* Toast */
            .pwa-toast {
                position: fixed;
                bottom: 30px;
                left: 50%;
                transform: translateX(-50%) translateY(100px);
                background: #333;
                color: white;
                padding: 12px 20px;
                border-radius: 30px;
                box-shadow: 0 5px 20px rgba(0, 0, 0, 0.3);
                z-index: 10002;
                font-size: 14px;
                font-weight: 500;
                opacity: 0;
                transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
            }
            
            .pwa-toast.show {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            
            .pwa-toast-success {
                background: #28a745;
            }
            
            .pwa-toast-error {
                background: #dc3545;
            }
            
            /* Mobile specific */
            @media (max-width: 480px) {
                .pwa-modal-card {
                    padding: 18px;
                    max-height: 90vh; /* לא יותר מ-90% מגובה המסך */
                    width: 92%;
                }
                
                .pwa-bell-icon {
                    font-size: 40px;
                }
                
                .pwa-modal-header h3 {
                    font-size: 18px;
                }
                
                .pwa-feature-item {
                    padding: 9px 10px;
                    font-size: 13px;
                }
                
                .pwa-modal-buttons {
                    gap: 8px;
                }
                
                .pwa-btn-allow {
                    padding: 12px;
                    font-size: 15px;
                }
                
                .pwa-btn-later {
                    padding: 10px;
                    font-size: 13px;
                }
            }
            
            /* Very small screens */
            @media (max-height: 600px) {
                .pwa-modal-card {
                    max-height: 340px;
                    padding: 15px;
                }
                
                .pwa-bell-icon {
                    font-size: 36px;
                    margin-bottom: 8px;
                }
                
                .pwa-modal-header {
                    margin-bottom: 15px;
                }
                
                .pwa-feature-item {
                    padding: 8px 10px;
                    margin-bottom: 8px;
                }
                
                .pwa-modal-buttons {
                    margin-top: 15px;
                }
            }
        `;
        
        document.head.appendChild(style);
    }
}

// יצירת אינסטנס גלובלי
window.pwaNotifications = new PWANotificationManager();

console.log('PWA Notifications Manager (Compact) loaded! 🔔');