export type ScratchpadAttachment = {
    id: number;
    role: string;
    mime: string;
    media_url: string;
};

/**
 * Renders a scratchpad entry's attached media: an <img> for a photo
 * capture, an <audio> player for a voice memo. Empty for a text entry
 * (attachments is simply []). No transcript is rendered for a voice entry,
 * since transcription doesn't exist yet in this phase.
 */
export function ScratchpadEntryMedia({
    attachments,
}: {
    attachments: ScratchpadAttachment[];
}) {
    if (attachments.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2">
            {attachments.map((attachment) => {
                if (attachment.mime.startsWith('image/')) {
                    return (
                        <img
                            key={attachment.id}
                            src={attachment.media_url}
                            alt="Scratch Pad capture"
                            className="max-h-64 w-auto rounded-md border object-contain"
                        />
                    );
                }

                if (attachment.mime.startsWith('audio/')) {
                    return (
                        <audio
                            key={attachment.id}
                            controls
                            src={attachment.media_url}
                            className="w-full max-w-sm"
                        />
                    );
                }

                return null;
            })}
        </div>
    );
}
