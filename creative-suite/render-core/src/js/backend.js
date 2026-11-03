// איזה מנוע אפשר להפעיל במכשיר: WebGPU (הכי מתקדם), WebGL2 עם float (הגיבוי), ואם אין אף אחד — 'canvas',
// כלומר composite.js במעבד. רק בודק, ולא יוצר מנוע. כל אפליקציה בונה את המנוע שלה מעל מה שנבחר.

/** WebGL2 עם מרקמי float שאפשר לצייר אליהם (EXT_color_buffer_float / half_float) — מה שמיזוג בלינארי צריך */
export function webgl2Supported() {
  try {
    const gl = document.createElement('canvas').getContext('webgl2');
    return !!(gl && (gl.getExtension('EXT_color_buffer_float') || gl.getExtension('EXT_color_buffer_half_float')));
  } catch { return false; }
}

/** יש מתאם WebGPU? (navigator.gpu לבד לא מספיק: בלי כרטיס requestAdapter מחזיר null) */
export async function webgpuAvailable() {
  try { return !!(globalThis.navigator?.gpu && await navigator.gpu.requestAdapter()); } catch { return false; }
}

/**
 * המנוע הטוב ביותר: 'webgpu' | 'webgl2' | 'canvas'.
 * prefer — 'webgl2' (או 'canvas') מכריח מנוע פשוט יותר: הגדרות אישיות, בדיקות.
 */
export async function pickBackend({ prefer } = {}) {
  if (prefer !== 'webgl2' && prefer !== 'canvas' && await webgpuAvailable()) return 'webgpu';
  if (prefer !== 'canvas' && webgl2Supported()) return 'webgl2';
  return 'canvas';
}
