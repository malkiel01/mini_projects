package com.mbeplus.guardedbrowser

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.util.Base64

/**
 * אימות Ed25519 במכשיר (lib/signing.php חותם, Ed25519.kt מאמת).
 *
 *   שני סוגי וקטורים: הסטנדרטיים מ-RFC 8032 (נכונות מול מקור חיצוני), ואחד שנוצר כאן מ-PHP
 *   עם אותו זרע — כדי לוודא שהאסימון שהשרת מפיק באמת מתאמת במכשיר, מקצה לקצה.
 */
class Ed25519Test {

    private fun hex(s: String): ByteArray =
        ByteArray(s.length / 2) { ((Character.digit(s[it * 2], 16) shl 4) + Character.digit(s[it * 2 + 1], 16)).toByte() }

    private fun b64u(s: String): ByteArray = Base64.getUrlDecoder().decode(s)

    @Test fun rfc8032Vector1Empty() {
        val pub = hex("d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a")
        val sig = hex("e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b")
        assertTrue(Ed25519.verify(pub, ByteArray(0), sig))
    }

    @Test fun rfc8032Vector2OneByte() {
        val pub = hex("3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c")
        val msg = hex("72")
        val sig = hex("92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00")
        assertTrue(Ed25519.verify(pub, msg, sig))
    }

    @Test fun tamperedSignatureRejected() {
        val pub = hex("d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a")
        val sig = hex("e5564300c360ac729086e2cc806e828a84877f1eb8e5d974d873e065224901555fb8821590a33bacc61e39701cf9b46bd25bf5f0595bbe24655141438e7a100b")
            .copyOf().also { it[0] = (it[0] + 1).toByte() }
        assertFalse(Ed25519.verify(pub, ByteArray(0), sig))
    }

    @Test fun wrongMessageRejected() {
        val pub = hex("3d4017c3e843895a92b70aa74d1b7ebc9c982ccf2ec4968cc0cd55f12af4660c")
        val sig = hex("92a009a9f0d4cab8720e820b5f642540a2b27b5416503f8fb3762223ebdb69da085ac1e43e15996e458f3613d0f11d8c387b2eaeb4302aeeb00d291612bb0c00")
        assertFalse(Ed25519.verify(pub, hex("73"), sig))   // 0x73 במקום 0x72
    }

    /** אסימון שחרור שנוצר ב-PHP (seed קבוע) — מתאמת מקצה לקצה. */
    @Test fun phpReleaseTokenVerifies() {
        val pub = b64u("6kpsY-KcUgq-9VB7Ey7F-ZVHdq6-vnuSQh7qaRRG0iw")
        val token = "eyJhY2N0Ijo3LCJpYXQiOjE3MDAwMDAwMDAsInR5cCI6InJlbGVhc2UifQ.hSAZOXewUfuTQ5OxuyN3EQ4UEzK4UAlqWOY6RqJOJ7MYVI-lBpWHpYLPKGXfH35Fe3B8Rx8dNoIjE9Tt5_iGBg"
        val parts = token.split(".")
        assertTrue(Ed25519.verify(pub, b64u(parts[0]), b64u(parts[1])))
        // מפתח אחר דוחה
        val other = b64u("d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a".let {
            Base64.getUrlEncoder().withoutPadding().encodeToString(hex(it))
        })
        assertFalse(Ed25519.verify(other, b64u(parts[0]), b64u(parts[1])))
    }
}
