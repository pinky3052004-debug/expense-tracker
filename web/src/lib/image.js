/**
 * Upload မတင်ခင် ပုံကို ချုံ့ (Supabase free storage 1GB)
 * ဘောင်ချာရှည်တွေ ဖတ်လို့ရအောင် အရှည်ဘက်ကို 1600px အထိ ခွင့်ပြု
 */
export async function compressImage(file, maxSide = 1600, quality = 0.72) {
  const bmp = await createImageBitmap(file); // EXIF လှည့်ပုံကို browser က ကိုင်ပေး
  const scale = Math.min(1, maxSide / Math.max(bmp.width, bmp.height));
  const w = Math.round(bmp.width * scale);
  const h = Math.round(bmp.height * scale);

  const canvas = document.createElement('canvas');
  canvas.width = w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#fff'; // PNG transparent → JPEG မှာ အနက်ရောင်မဖြစ်အောင်
  ctx.fillRect(0, 0, w, h);
  ctx.drawImage(bmp, 0, 0, w, h);
  bmp.close?.();

  const blob = await new Promise((res) => canvas.toBlob(res, 'image/jpeg', quality));
  if (!blob) throw new Error('compress failed');
  return blob;
}
