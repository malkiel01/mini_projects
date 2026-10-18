package com.mbeplus.guardedbrowser

import java.math.BigInteger
import java.security.MessageDigest

/**
 * אימות חתימת Ed25519 — טהור, בלי ספריות חיצוניות ובלי תלות ב-API 33.
 *
 *   ‏java.security.Signature("Ed25519") קיים רק מ-API 33, ו-minSdk כאן 24. מימוש האימות
 *   לפי RFC 8032 ב-BigInteger עובד בכל גרסה. זה *אימות* בלבד (מפתח ציבורי על נתונים
 *   ציבוריים), ולכן אין צורך בזמן קבוע — רק בנכונות.
 *
 *   השרת חותם שחרור, server_url וכדומה (lib/signing.php); המכשיר דוחה כל אחד מהם בלי
 *   חתימה תקפה מול המפתח שהוצמד בצימוד. בלי זה היה אפשר "לשחרר" מכשיר בלי השרת.
 */
object Ed25519 {

    // ‏BigInteger.TWO קיים רק מ-API 31; minSdk כאן 24, לכן קבוע מקומי.
    private val TWO = BigInteger.valueOf(2)
    private val P = TWO.pow(255) - BigInteger.valueOf(19)
    private val L = TWO.pow(252) +
        BigInteger("27742317777372353535851937790883648493")           // סדר החבורה
    // ‏.mod(P) ולא %: ב-Kotlin אופרטור % על BigInteger הוא rem (עלול להחזיר שלילי).
    private val D = (BigInteger.valueOf(-121665) *
        modInv(BigInteger.valueOf(121666))).mod(P)
    private val SQRT_M1 = TWO.modPow((P - BigInteger.ONE) / BigInteger.valueOf(4), P)

    private fun modInv(x: BigInteger) = x.modPow(P - TWO, P)
    private fun mod(x: BigInteger): BigInteger = x.mod(P)

    /** נקודה בקואורדינטות מורחבות (X, Y, Z, T). */
    private class Pt(val x: BigInteger, val y: BigInteger, val z: BigInteger, val t: BigInteger)

    private val G: Pt = run {
        val y = BigInteger.valueOf(4) * modInv(BigInteger.valueOf(5)) % P
        val x = recoverX(y, BigInteger.ZERO)!!
        Pt(x, y, BigInteger.ONE, mod(x * y))
    }

    private fun add(a: Pt, b: Pt): Pt {
        val aa = mod((a.y - a.x) * (b.y - b.x))
        val bb = mod((a.y + a.x) * (b.y + b.x))
        val cc = mod(TWO * a.t * b.t * D)
        val dd = mod(TWO * a.z * b.z)
        val e = bb - aa; val f = dd - cc; val g = dd + cc; val h = bb + aa
        return Pt(mod(e * f), mod(g * h), mod(f * g), mod(e * h))
    }

    private fun mul(scalar: BigInteger, point: Pt): Pt {
        var q = Pt(BigInteger.ZERO, BigInteger.ONE, BigInteger.ONE, BigInteger.ZERO)   // נקודת האפס
        var p = point
        var s = scalar
        while (s > BigInteger.ZERO) {
            if (s.testBit(0)) q = add(q, p)
            p = add(p, p)
            s = s.shiftRight(1)
        }
        return q
    }

    private fun equal(a: Pt, b: Pt): Boolean =
        mod(a.x * b.z - b.x * a.z).signum() == 0 && mod(a.y * b.z - b.y * a.z).signum() == 0

    private fun recoverX(y: BigInteger, sign: BigInteger): BigInteger? {
        if (y >= P) return null
        val x2 = mod((y * y - BigInteger.ONE) * modInv(D * y * y + BigInteger.ONE))
        if (x2.signum() == 0) return if (sign.signum() == 1) null else BigInteger.ZERO
        var x = x2.modPow((P + BigInteger.valueOf(3)) / BigInteger.valueOf(8), P)
        if (mod(x * x - x2).signum() != 0) x = mod(x * SQRT_M1)
        if (mod(x * x - x2).signum() != 0) return null
        if (x.testBit(0) != (sign.signum() == 1)) x = P - x
        return x
    }

    /** מספר little-endian מתוך בתים (Ed25519 עובד ב-little-endian). */
    private fun leToBig(b: ByteArray): BigInteger {
        var r = BigInteger.ZERO
        for (i in b.indices.reversed()) r = r.shiftLeft(8) + BigInteger.valueOf((b[i].toInt() and 0xff).toLong())
        return r
    }

    private fun decompress(s: ByteArray): Pt? {
        if (s.size != 32) return null
        val num = leToBig(s)
        val sign = if (num.testBit(255)) BigInteger.ONE else BigInteger.ZERO
        val y = num.and(TWO.pow(255) - BigInteger.ONE)
        val x = recoverX(y, sign) ?: return null
        return Pt(x, y, BigInteger.ONE, mod(x * y))
    }

    private fun sha512(vararg parts: ByteArray): ByteArray {
        val md = MessageDigest.getInstance("SHA-512")
        for (p in parts) md.update(p)
        return md.digest()
    }

    /** ‏true אם החתימה (64 בתים) תקפה על ההודעה מול המפתח הציבורי (32 בתים). */
    fun verify(publicKey: ByteArray, msg: ByteArray, signature: ByteArray): Boolean {
        if (publicKey.size != 32 || signature.size != 64) return false
        return try {
            val a = decompress(publicKey) ?: return false
            val rBytes = signature.copyOfRange(0, 32)
            val r = decompress(rBytes) ?: return false
            val s = leToBig(signature.copyOfRange(32, 64))
            if (s >= L) return false
            val h = leToBig(sha512(rBytes, publicKey, msg)).mod(L)
            equal(mul(s, G), add(r, mul(h, a)))
        } catch (e: Exception) { false }
    }
}
