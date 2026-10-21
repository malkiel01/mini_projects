package com.mbeplus.guardedbrowser

import com.mbeplus.guardedbrowser.ServerVerdict.Kind
import org.junit.Assert.assertEquals
import org.junit.Test

/** חוזה ההכרעה — היעדר allowed לעולם אינו היתר (שלב 7). */
class ServerVerdictTest {

    private fun k(hasJson: Boolean, ok: Boolean, allowed: Boolean?, code: String = "", retry: Boolean = false) =
        ServerVerdict.of(hasJson, ok, allowed, code, "", retry).kind

    @Test fun allowedTrueIsAllow() = assertEquals(Kind.ALLOW, k(true, true, true))

    @Test fun allowedFalseIsDeny() = assertEquals(Kind.DENY, k(true, true, false))

    @Test fun missingAllowedOnOkIsDenyNotAllow() = assertEquals(Kind.DENY, k(true, true, null))

    @Test fun suspendedAccountWithoutAllowedIsDeny() =
        // הבאג: 403 account_suspended בלי allowed — הפעימה המשיכה לגלוש.
        assertEquals(Kind.DENY, k(true, false, null, "account_suspended"))

    @Test fun unauthorizedIsDeny() = assertEquals(Kind.DENY, k(true, false, false, "unauthorized"))

    @Test fun notOkWinsOverAllowedTrue() = assertEquals(Kind.DENY, k(true, false, true, "account_pending"))

    @Test fun noJsonIsUnknown() = assertEquals(Kind.UNKNOWN, k(false, false, null))

    @Test fun serverErrorIsUnknown() = assertEquals(Kind.UNKNOWN, k(true, false, null, "server"))

    @Test fun rateLimitIsUnknown() = assertEquals(Kind.UNKNOWN, k(true, false, null, "rate_limited"))

    @Test fun retryFlagIsUnknown() = assertEquals(Kind.UNKNOWN, k(true, false, null, "whatever", retry = true))

    @Test fun denyCarriesAReason() {
        val v = ServerVerdict.of(true, false, null, "account_suspended", "")
        assertEquals("account_suspended", v.code)
        assertEquals(true, v.reason.isNotEmpty())
    }
}
