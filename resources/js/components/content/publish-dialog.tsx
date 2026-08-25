import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type PublishDialogProps = {
    disabled: boolean;
    disabledReason?: string | null;
    publishState: string;
    publishError?: string | null;
    publishUrl: string;
    entityLabel: string;
};

export function PublishStatusBanner({
    publishState,
    publishError,
}: Pick<PublishDialogProps, 'publishState' | 'publishError'>) {
    if (publishState === 'idle' && !publishError) {
        return null;
    }

    return (
        <div
            className={
                publishState === 'failed'
                    ? 'rounded-lg border border-destructive/50 bg-destructive/5 p-4 text-sm'
                    : 'rounded-lg border bg-muted/30 p-4 text-sm'
            }
        >
            <p className="font-medium">Publish status: {publishState}</p>
            {publishError && (
                <p className="mt-1 text-muted-foreground">{publishError}</p>
            )}
        </div>
    );
}

export default function PublishDialog({
    disabled,
    disabledReason,
    publishState,
    publishError,
    publishUrl,
    entityLabel,
}: PublishDialogProps) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<'now' | 'schedule'>('now');
    const publishBusy = ['queued', 'running'].includes(publishState);

    function openDialog(nextMode: 'now' | 'schedule') {
        setMode(nextMode);
        setOpen(true);
    }

    return (
        <div className="space-y-3">
            <PublishStatusBanner
                publishState={publishState}
                publishError={publishError}
            />

            {!disabledReason && !publishBusy && (
                <p className="text-sm text-muted-foreground">
                    Queue {entityLabel} through PostSyncer.
                </p>
            )}

            {disabledReason && (
                <p className="text-sm text-muted-foreground">{disabledReason}</p>
            )}

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    disabled={disabled}
                    onClick={() => openDialog('now')}
                >
                    Publish now
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    disabled={disabled}
                    onClick={() => openDialog('schedule')}
                >
                    Schedule
                </Button>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {mode === 'now'
                                ? `Publish ${entityLabel} now`
                                : `Schedule ${entityLabel}`}
                        </DialogTitle>
                        <DialogDescription>
                            {mode === 'now'
                                ? 'PostSyncer will publish as soon as the job runs.'
                                : 'Choose when PostSyncer should publish.'}
                        </DialogDescription>
                    </DialogHeader>

                    <Form
                        action={publishUrl}
                        method="post"
                        onSuccess={() => setOpen(false)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                {mode === 'schedule' ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="when">
                                            Publish at (local time)
                                        </Label>
                                        <Input
                                            id="when"
                                            name="when"
                                            type="datetime-local"
                                            required
                                        />
                                        <InputError message={errors.when} />
                                    </div>
                                ) : null}

                                <InputError message={errors.publish} />

                                <DialogFooter>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setOpen(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button disabled={processing}>
                                        {mode === 'now'
                                            ? 'Publish now'
                                            : 'Schedule publish'}
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
