import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    /** Lifecycle status: draft | scheduled | posted | … Used to word the banner. */
    contentStatus?: string | null;
    publishUrl: string;
    entityLabel: string;
    needsConfirmAsk?: boolean;
    retryOnly?: boolean;
    showStatus?: boolean;
};

function publishStatusLabel(
    publishState: string,
    contentStatus?: string | null,
): string {
    const lifecycle = (contentStatus || '').toLowerCase();

    if (publishState === 'queued') {
        return 'Queuing on PostSyncer…';
    }

    if (publishState === 'running') {
        return 'Sending to PostSyncer…';
    }

    if (publishState === 'failed') {
        return 'PostSyncer job failed';
    }

    if (publishState === 'succeeded') {
        if (lifecycle === 'scheduled') {
            return 'Scheduled on PostSyncer. Not published yet.';
        }

        if (lifecycle === 'posted') {
            return 'Published on PostSyncer.';
        }

        return 'PostSyncer job succeeded.';
    }

    return `PostSyncer job: ${publishState}`;
}

export function PublishStatusBanner({
    publishState,
    publishError,
    contentStatus,
}: Pick<
    PublishDialogProps,
    'publishState' | 'publishError' | 'contentStatus'
>) {
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
            <p className="font-medium">
                {publishStatusLabel(publishState, contentStatus)}
            </p>
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
    contentStatus,
    publishUrl,
    entityLabel,
    needsConfirmAsk = false,
    retryOnly = false,
    showStatus = true,
}: PublishDialogProps) {
    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<'now' | 'schedule' | 'retry'>('now');
    const [confirmAskChecked, setConfirmAskChecked] = useState(false);
    const publishBusy = ['queued', 'running'].includes(publishState);

    function openDialog(nextMode: 'now' | 'schedule' | 'retry') {
        setMode(nextMode);
        setConfirmAskChecked(false);
        setOpen(true);
    }

    function handleOpenChange(nextOpen: boolean) {
        setOpen(nextOpen);

        if (!nextOpen) {
            setConfirmAskChecked(false);
        }
    }

    const submitDisabled = needsConfirmAsk && !confirmAskChecked;

    return (
        <div className="space-y-3">
            {showStatus && (
                <PublishStatusBanner
                    publishState={publishState}
                    publishError={publishError}
                    contentStatus={contentStatus}
                />
            )}

            {!disabledReason && !publishBusy && (
                <p className="text-sm text-muted-foreground">
                    Queue {entityLabel} through PostSyncer.
                </p>
            )}

            {disabledReason && (
                <p className="text-sm text-muted-foreground">
                    {disabledReason}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                {retryOnly ? (
                    <Button
                        type="button"
                        disabled={disabled}
                        onClick={() => openDialog('retry')}
                    >
                        Retry publish
                    </Button>
                ) : (
                    <>
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
                    </>
                )}
            </div>

            <Dialog open={open} onOpenChange={handleOpenChange}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {mode === 'retry'
                                ? `Retry ${entityLabel}`
                                : mode === 'now'
                                  ? `Publish ${entityLabel} now`
                                  : `Schedule ${entityLabel}`}
                        </DialogTitle>
                        <DialogDescription>
                            {mode === 'retry'
                                ? 'Resume the failed PostSyncer publish without changing its original options.'
                                : mode === 'now'
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

                                {needsConfirmAsk ? (
                                    <div className="flex items-start gap-3 rounded-lg border bg-muted/20 p-3">
                                        <Checkbox
                                            id="confirm_ask"
                                            name="confirm_ask"
                                            value="1"
                                            checked={confirmAskChecked}
                                            onCheckedChange={(checked) =>
                                                setConfirmAskChecked(
                                                    checked === true,
                                                )
                                            }
                                        />
                                        <div className="grid gap-1">
                                            <Label
                                                htmlFor="confirm_ask"
                                                className="leading-snug"
                                            >
                                                I confirm ask-gated platforms
                                            </Label>
                                            <p className="text-sm text-muted-foreground">
                                                Some selected platforms (e.g.
                                                English Twitter, Threads, or
                                                Bluesky photo posts) need
                                                explicit approval before
                                                publishing.
                                            </p>
                                        </div>
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
                                    <Button
                                        disabled={processing || submitDisabled}
                                    >
                                        {mode === 'now'
                                            ? 'Publish now'
                                            : mode === 'retry'
                                              ? 'Retry publish'
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
