// TIFF — כתיבה של תמונה משוטחת: RGBA של 8 ביט, אלפא ישר (ExtraSamples = 2), בלי דחיסה, רצועה אחת.
// נפתח בפוטושופ, GIMP, Affinity ו-Preview. חשבון טהור, נבדק ב-node.

/** rgba (Uint8Array / Uint8ClampedArray של w×h×4) → Uint8Array של TIFF */
export function encodeTIFF(rgba, w, h, dpi = 72) {
  const tags = [
    [256, 4, 1, w],           // ImageWidth
    [257, 4, 1, h],           // ImageLength
    [258, 3, 4, 'bps'],       // BitsPerSample: 8,8,8,8
    [259, 3, 1, 1],           // Compression: בלי
    [262, 3, 1, 2],           // Photometric: RGB
    [273, 4, 1, 'data'],      // StripOffsets
    [277, 3, 1, 4],           // SamplesPerPixel
    [278, 4, 1, h],           // RowsPerStrip
    [279, 4, 1, w * h * 4],   // StripByteCounts
    [282, 5, 1, 'xres'],      // XResolution
    [283, 5, 1, 'yres'],      // YResolution
    [284, 3, 1, 1],           // PlanarConfiguration: רציף
    [296, 3, 1, 2],           // ResolutionUnit: אינץ'
    [338, 3, 1, 2],           // ExtraSamples: אלפא ישר
  ];
  const ifd = 8, ifdLen = 2 + tags.length * 12 + 4;
  const bps = ifd + ifdLen, xres = bps + 8, yres = xres + 8, data = yres + 8;
  const at = { bps, xres, yres, data };
  const out = new Uint8Array(data + w * h * 4);
  const v = new DataView(out.buffer);
  out.set([0x49, 0x49, 42, 0]);   // "II*\0" — little endian
  v.setUint32(4, ifd, true);
  v.setUint16(ifd, tags.length, true);
  tags.forEach(([tag, type, count, val], i) => {
    const o = ifd + 2 + i * 12;
    v.setUint16(o, tag, true); v.setUint16(o + 2, type, true); v.setUint32(o + 4, count, true);
    const x = typeof val === 'string' ? at[val] : val;
    if (type === 3 && typeof val !== 'string') v.setUint16(o + 8, x, true); else v.setUint32(o + 8, x, true);
  });
  v.setUint32(ifd + 2 + tags.length * 12, 0, true);   // אין IFD נוסף
  for (let i = 0; i < 4; i++) v.setUint16(bps + i * 2, 8, true);
  v.setUint32(xres, dpi, true); v.setUint32(xres + 4, 1, true);
  v.setUint32(yres, dpi, true); v.setUint32(yres + 4, 1, true);
  out.set(rgba, data);
  return out;
}
