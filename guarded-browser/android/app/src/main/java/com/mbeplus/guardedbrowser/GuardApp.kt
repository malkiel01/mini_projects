package com.mbeplus.guardedbrowser

import android.app.Application

/**
 * נקודת הפתיחה של התהליך — לפני כל מסך ולפני השירות.
 *
 * כאן Api מקבל את כתובת השרת השמורה, כדי שגם השירות שרץ ברקע (בלי
 * מסך) יפנה לכתובת הנכונה אחרי מעבר דומיין.
 */
class GuardApp : Application() {
    override fun onCreate() {
        super.onCreate()
        Api.init(Store(this))
    }
}
