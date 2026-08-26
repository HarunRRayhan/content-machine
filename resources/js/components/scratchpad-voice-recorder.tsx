import { router } from '@inertiajs/react';
import {
    forwardRef,
    useEffect,
    useImperativeHandle,
    useRef,
    useState,
} from 'react';
import ScratchpadController from '@/actions/App/Http/Controllers/Scratchpad/ScratchpadController';

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

export type ScratchpadVoiceRecorderHandle = {
    start: () => Promise<void>;
    stop: () => void;
};

type Props = {
    /** Called after a recording finishes uploading successfully. */
    onSaved?: () => void;
    language?: string | null;
    onRecordingChange?: (recording: boolean) => void;
    onUploadingChange?: (uploading: boolean) => void;
};

/**
 * Voice-memo capture without its own start button. The Scratch Pad page
 * owns the microphone control and calls start/stop through the handle.
 * On stop, assembles the recorded chunks into a Blob and POSTs it to
 * scratchpad.voice the same way the photo form uploads a file.
 */
export const ScratchpadVoiceRecorder = forwardRef<
    ScratchpadVoiceRecorderHandle,
    Props
>(function ScratchpadVoiceRecorder(
    { onSaved, language, onRecordingChange, onUploadingChange },
    ref,
) {
    const [recording, setRecording] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const recorderRef = useRef<MediaRecorder | null>(null);
    const chunksRef = useRef<BlobPart[]>([]);
    const streamRef = useRef<MediaStream | null>(null);
    const startingRef = useRef(false);
    const languageRef = useRef(language);
    languageRef.current = language;

    async function startRecording() {
        if (startingRef.current || recorderRef.current) {
            return;
        }

        setError(null);

        const mimeType = pickSupportedMimeType();

        if (mimeType === null) {
            setError('Voice recording is not supported in this browser.');

            return;
        }

        startingRef.current = true;

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
                recorderRef.current = null;
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
            onRecordingChange?.(true);
        } catch {
            setError('Could not access the microphone.');
            onRecordingChange?.(false);
        } finally {
            startingRef.current = false;
        }
    }

    function stopRecording() {
        recorderRef.current?.stop();
        setRecording(false);
        onRecordingChange?.(false);
    }

    useEffect(() => {
        return () => {
            const recorder = recorderRef.current;

            if (recorder && recorder.state !== 'inactive') {
                recorder.onstop = null;
                recorder.stop();
            }

            streamRef.current?.getTracks().forEach((track) => track.stop());
            streamRef.current = null;
            recorderRef.current = null;
        };
    }, []);

    function uploadRecording(blob: Blob, mimeType: string) {
        setUploading(true);
        onUploadingChange?.(true);

        const file = new File([blob], `voice-note.${extensionFor(mimeType)}`, {
            type: mimeType,
        });

        router.post(
            ScratchpadController.storeVoice.url(),
            { audio: file, language: languageRef.current ?? undefined },
            {
                forceFormData: true,
                onSuccess: () => onSaved?.(),
                onError: () => setError('Could not save the recording.'),
                onFinish: () => {
                    setUploading(false);
                    onUploadingChange?.(false);
                },
            },
        );
    }

    useImperativeHandle(ref, () => ({
        start: startRecording,
        stop: stopRecording,
    }));

    if (!recording && !uploading && error === null) {
        return null;
    }

    return (
        <div className="space-y-1">
            {recording && (
                <p className="text-sm text-muted-foreground">Recording…</p>
            )}
            {uploading && (
                <p className="text-sm text-muted-foreground">Saving…</p>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
});
