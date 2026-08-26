import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ScratchpadController from '@/actions/App/Http/Controllers/Scratchpad/ScratchpadController';
import { Button } from '@/components/ui/button';

const PREFERRED_MIME_TYPES = [
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/mp4',
];

function pickSupportedMimeType(): string | null {
    if (typeof MediaRecorder === 'undefined') {
        return null;
    }

    return (
        PREFERRED_MIME_TYPES.find((type) =>
            MediaRecorder.isTypeSupported(type),
        ) ?? null
    );
}

function extensionFor(mimeType: string): string {
    return mimeType.startsWith('audio/mp4') ? 'm4a' : 'webm';
}

type Props = {
    /** Called after a recording finishes uploading successfully. */
    onSaved?: () => void;
};

/**
 * Record/stop toggle for a voice memo, no waveform, no time limit UI. On
 * stop, assembles the recorded chunks into a Blob and submits it the same
 * way the photo capture form does (a multipart POST to
 * scratchpad.voice), relying on Inertia's default
 * post-redirect-GET to refresh the entry list.
 */
export function ScratchpadVoiceRecorder({ onSaved }: Props) {
    const [recording, setRecording] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const recorderRef = useRef<MediaRecorder | null>(null);
    const chunksRef = useRef<BlobPart[]>([]);
    const streamRef = useRef<MediaStream | null>(null);

    async function startRecording() {
        setError(null);

        const mimeType = pickSupportedMimeType();

        if (mimeType === null) {
            setError('Voice recording is not supported in this browser.');

            return;
        }

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                audio: true,
            });
            streamRef.current = stream;
            chunksRef.current = [];

            const recorder = new MediaRecorder(stream, { mimeType });

            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) {
                    chunksRef.current.push(event.data);
                }
            };

            recorder.onstop = () => {
                streamRef.current?.getTracks().forEach((track) => track.stop());
                streamRef.current = null;
                uploadRecording(
                    new Blob(chunksRef.current, { type: mimeType }),
                    mimeType,
                );
            };

            recorderRef.current = recorder;
            // A timeslice, not a bare start(): some browsers truncate the
            // final chunk of a bare recording, this flushes periodically.
            recorder.start(1000);
            setRecording(true);
        } catch {
            setError('Could not access the microphone.');
        }
    }

    function stopRecording() {
        recorderRef.current?.stop();
        setRecording(false);
    }

    function uploadRecording(blob: Blob, mimeType: string) {
        setUploading(true);

        const file = new File([blob], `voice-note.${extensionFor(mimeType)}`, {
            type: mimeType,
        });

        router.post(
            ScratchpadController.storeVoice.url(),
            { audio: file },
            {
                forceFormData: true,
                onSuccess: () => onSaved?.(),
                onError: () => setError('Could not save the recording.'),
                onFinish: () => setUploading(false),
            },
        );
    }

    return (
        <div className="space-y-2">
            <Button
                type="button"
                variant={recording ? 'destructive' : 'outline'}
                disabled={uploading}
                onClick={recording ? stopRecording : startRecording}
            >
                {recording
                    ? 'Stop recording'
                    : uploading
                      ? 'Saving…'
                      : 'Record a voice note'}
            </Button>
            {recording && (
                <p className="text-sm text-muted-foreground">Recording…</p>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}
