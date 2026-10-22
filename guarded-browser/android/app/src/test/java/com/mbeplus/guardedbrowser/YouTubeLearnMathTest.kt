package com.mbeplus.guardedbrowser

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** מצב למידה ביוטיוב (2026-10-22): בלי ניגון אוטומטי, ורק הסרטונים שבתור. */
class YouTubeLearnMathTest {

    @Test fun nextVideoRightAfterEndIsAutoplay() =
        assertTrue(YouTubeLearnMath.blocksAutoAdvance("aaa", 1_000, "bbb", 6_000))

    @Test fun replaySameVideoIsFine() =
        assertFalse(YouTubeLearnMath.blocksAutoAdvance("aaa", 1_000, "aaa", 6_000))

    @Test fun laterChoiceIsTheChilds() =
        assertFalse(YouTubeLearnMath.blocksAutoAdvance("aaa", 1_000, "bbb", 1_000 + YouTubeLearnMath.AUTO_ADVANCE_MS + 1))

    @Test fun nothingEndedNothingBlocked() =
        assertFalse(YouTubeLearnMath.blocksAutoAdvance("", 0, "bbb", 6_000))

    @Test fun reportOnlyOnChange() {
        assertTrue(YouTubeLearnMath.shouldReport("", 0, "{\"related\":true}", 10))
        assertFalse(YouTubeLearnMath.shouldReport("{\"related\":true}", 10, "{\"related\":true}", 20))
        assertTrue(YouTubeLearnMath.shouldReport("{\"related\":true}", 10, "{\"related\":true}", 10 + 25 * 3600_000L))
    }

    private val queued = mapOf("video" to mapOf("abcdefghijk" to "allow"), "handle" to mapOf("teacher" to "allow"))
    private val learn = PlatformRule(mode = "learn", allowSearch = true, allowShorts = true)
    private fun yt(url: String, main: Boolean = true) =
        PolicyEngine.youTubeVerdict(PolicyEngine.normalize(url)!!, learn, queued, main).code

    @Test fun onlyQueuedVideos() {
        assertEquals("yt_video_allowed", yt("https://m.youtube.com/watch?v=abcdefghijk"))
        assertEquals("yt_learn_queue", yt("https://m.youtube.com/watch?v=zzzzzzzzzzz"))
        // ערוץ מאושר לא פותח את כל הסרטונים שלו במצב למידה
        assertEquals("yt_learn_queue", yt("https://m.youtube.com/@teacher"))
    }

    @Test fun noShortsSearchOrHomeEvenIfAllowedElsewhere() {
        assertEquals("yt_no_shorts", yt("https://m.youtube.com/shorts/abcdefghijk"))
        assertEquals("yt_learn_queue", yt("https://m.youtube.com/results?search_query=x"))
        assertEquals("yt_learn_queue", yt("https://m.youtube.com/"))
        assertEquals("yt_no_search", yt("https://m.youtube.com/youtubei/v1/search?key=x", main = false))
    }
}
