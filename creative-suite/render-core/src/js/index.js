// render-core — הרכבת השכבות לכל החבילה. נקודת כניסה אחת; אפשר גם לייבא כל קובץ לבד.
// ה-API המלא: creative-suite/render-core/README.md.

export { BLEND_MODES, blendIndex, blendChannel, blendOver, GLSL_BLEND, WGSL_BLEND } from './blend.js';
export { srgbToLinear, linearToSrgb, SRGB_TO_LINEAR, GLSL_DECODE, GLSL_ENCODE, GLSL_COLOR, WGSL_COLOR } from './color.js';
export { composite } from './composite.js';
export { webgl2Supported, webgpuAvailable, pickBackend } from './backend.js';
export { deviceBudget, suggestSize, megapixels, perfFactor, perfLabel, perfCacheValid, BYTES_PER_PIXEL, PLAN_LAYERS, PERF_FAST, PERF_SLOW } from './budget.js';
