import { cn } from '@/lib/utils';

type TemplatePreviewProps = {
    src: string;
    alt: string;
    letter?: string;
    className?: string;
};

export default function TemplatePreview({
    src,
    alt,
    letter,
    className,
}: TemplatePreviewProps) {
    return (
        <div className={cn('relative overflow-hidden bg-muted', className)}>
            <img
                src={src}
                alt={alt}
                className="size-full object-cover transition duration-300 group-hover:scale-[1.015]"
                loading="lazy"
            />
            {letter && (
                <span
                    aria-hidden="true"
                    className="absolute top-3 left-3 flex size-9 items-center justify-center rounded-lg bg-black/70 text-sm font-semibold text-white shadow-sm backdrop-blur-sm"
                >
                    {letter}
                </span>
            )}
        </div>
    );
}
