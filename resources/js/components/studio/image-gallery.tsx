import { useState } from 'react';
import ImageLightbox, {
    toLightboxImages,
} from '@/components/studio/image-lightbox';

type ImageGalleryProps = {
    images: Array<{
        filename?: string;
        name?: string;
        url?: string | null;
        mime?: string | null;
    }>;
};

export default function ImageGallery({ images }: ImageGalleryProps) {
    const viewable = toLightboxImages(images);
    const [openIndex, setOpenIndex] = useState<number | null>(null);

    if (viewable.length === 0) {
        return null;
    }

    return (
        <section className="pane">
            <div className="pane-head">
                <span className="k">Images</span>
            </div>
            <div className="post-images-gallery">
                {viewable.map((image, index) => (
                    <button
                        key={`${image.url}-${index}`}
                        type="button"
                        className="post-images-gallery-shot"
                        onClick={() => setOpenIndex(index)}
                    >
                        <img src={image.url} alt={image.name} />
                        <span>{image.name}</span>
                    </button>
                ))}
            </div>
            <ImageLightbox
                images={viewable}
                startIndex={openIndex}
                onClose={() => setOpenIndex(null)}
            />
        </section>
    );
}
