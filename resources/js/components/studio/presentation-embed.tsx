type Props = {
    src: string;
    title: string;
};

export default function PresentationEmbed({ src, title }: Props) {
    return (
        <iframe
            className="pres-embed"
            src={src}
            title={`${title} presentation`}
            allowFullScreen
        />
    );
}
