/**
 * Normalize phone/camera photos for reliable upload (HEIC/large/weird MIME → JPEG).
 */
export async function prepareListingImages(files: File[], maxSide = 1600, quality = 0.85): Promise<File[]> {
  const out: File[] = [];
  for (let i = 0; i < files.length; i++) {
    const file = files[i];
    try {
      const prepared = await compressImageFile(file, maxSide, quality);
      out.push(prepared);
    } catch {
      // Keep original if browser cannot decode (server will validate)
      out.push(file);
    }
  }
  return out;
}

async function compressImageFile(file: File, maxSide: number, quality: number): Promise<File> {
  const bitmap = await loadImageBitmap(file);
  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height));
  const w = Math.max(1, Math.round(bitmap.width * scale));
  const h = Math.max(1, Math.round(bitmap.height * scale));

  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    bitmap.close?.();
    throw new Error('canvas');
  }
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, w, h);
  ctx.drawImage(bitmap, 0, 0, w, h);
  bitmap.close?.();

  const blob: Blob = await new Promise((resolve, reject) => {
    canvas.toBlob(
      b => (b ? resolve(b) : reject(new Error('toBlob'))),
      'image/jpeg',
      quality,
    );
  });

  const base = (file.name || `photo_${Date.now()}`).replace(/\.[^.]+$/, '');
  return new File([blob], `${base}.jpg`, { type: 'image/jpeg', lastModified: Date.now() });
}

async function loadImageBitmap(file: File): Promise<ImageBitmap> {
  if (typeof createImageBitmap === 'function') {
    try {
      return await createImageBitmap(file);
    } catch {
      /* fall through */
    }
  }
  const url = URL.createObjectURL(file);
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const el = new Image();
      el.onload = () => resolve(el);
      el.onerror = () => reject(new Error('decode'));
      el.src = url;
    });
    const canvas = document.createElement('canvas');
    canvas.width = img.naturalWidth || img.width;
    canvas.height = img.naturalHeight || img.height;
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('canvas');
    ctx.drawImage(img, 0, 0);
    return await createImageBitmap(canvas);
  } finally {
    URL.revokeObjectURL(url);
  }
}
