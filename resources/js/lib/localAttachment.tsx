import { useCallback } from 'react';

export function LocalAttachmentImage({ file, alt, className }: { file: File; alt: string; className: string }) {
  const attachImage = useCallback((image: HTMLImageElement | null) => {
    if (!image) return;
    const url = URL.createObjectURL(file);
    image.src = url;

    return () => URL.revokeObjectURL(url);
  }, [file]);

  return <img ref={attachImage} alt={alt} className={className} />;
}

export const MAX_ATTACHMENT_BYTES = 3 * 1024 * 1024;

export async function fitEditedAttachment(file: File): Promise<File | null> {
  const name = file.name.replace(/\.(jpe?g|webp|gif)$/i, '.png');

  if (file.size <= MAX_ATTACHMENT_BYTES) return new File([file], name, { type: 'image/png' });

  const image = await createImageBitmap(file);

  try {
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d');

    if (!context) return null;

    canvas.width = image.width;
    canvas.height = image.height;

    do {
      canvas.width = Math.max(1, Math.floor(canvas.width * 0.7));
      canvas.height = Math.max(1, Math.floor(canvas.height * 0.7));
      context.drawImage(image, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'));

      if (!blob) return null;

      if (blob.size <= MAX_ATTACHMENT_BYTES) return new File([blob], name, { type: 'image/png' });
    } while (canvas.width > 1 || canvas.height > 1);

    return null;
  } finally {
    image.close();
  }
}
