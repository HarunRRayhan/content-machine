import { Form, Head } from '@inertiajs/react';
import TelegramBotConfigController from '@/actions/App/Http/Controllers/Telegram/TelegramBotConfigController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes/dashboard';
import { edit } from '@/routes/dashboard/telegram';

type PageProps = {
    connected: boolean;
    botUsername: string | null;
    connectedAt: string | null;
};

export default function TelegramEdit({
    connected,
    botUsername,
    connectedAt,
}: PageProps) {
    return (
        <>
            <Head title="Telegram" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Telegram"
                    description="Disabled until a bot token is added. Message @BotFather on Telegram to create a bot and get one."
                />

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <div className="flex items-center gap-2">
                        <Badge variant={connected ? 'default' : 'outline'}>
                            {connected ? 'Enabled' : 'Disabled'}
                        </Badge>
                        {connected && botUsername && (
                            <span className="text-sm text-muted-foreground">
                                Connected as @{botUsername}
                                {connectedAt &&
                                    ` on ${new Date(connectedAt).toLocaleString()}`}
                            </span>
                        )}
                    </div>

                    {connected ? (
                        <Form
                            {...TelegramBotConfigController.destroy.form()}
                            className="space-y-4"
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Disconnect
                                </Button>
                            )}
                        </Form>
                    ) : (
                        <Form
                            {...TelegramBotConfigController.update.form()}
                            resetOnSuccess
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="bot_token">
                                            Bot token
                                        </Label>
                                        <Input
                                            id="bot_token"
                                            type="password"
                                            name="bot_token"
                                            required
                                            autoComplete="off"
                                            placeholder="123456:AA..."
                                        />
                                        <InputError
                                            message={errors.bot_token}
                                        />
                                    </div>

                                    <Button disabled={processing}>
                                        Connect
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </div>
        </>
    );
}

TelegramEdit.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Telegram', href: edit() },
    ],
};
