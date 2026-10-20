'use strict';
const copyButton = document.getElementById('copy');
copyButton.addEventListener('click', async () => {
  const status = document.getElementById('copy-status');
  try {
    await navigator.clipboard.writeText(document.getElementById('handoff').textContent.trim());
    status.textContent = 'ההודעה הועתקה. אפשר להדביק אותה בצ׳אט החדש.';
  } catch {
    document.querySelector('details').open = true;
    status.textContent = 'לא ניתן להעתיק אוטומטית. אפשר לסמן ולהעתיק את ההודעה שמופיעה למטה.';
  }
});
