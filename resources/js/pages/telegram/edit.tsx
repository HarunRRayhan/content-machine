import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import TelegramBotConfigController from '@/actions/App/Http/Controllers/Telegram/TelegramBotConfigController';
import TelegramBotLinkController from '@/actions/App/Http/Controllers/Telegram/TelegramBotLinkController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SettingsShell } from '@/components/workspace-settings/settings-shell';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';
import { index as aiProvidersIndex } from '@/routes/settings/ai-providers';
import { edit } from '@/routes/settings/telegram';

type LinkInfo = {
    telegramUsername: string | null;
    linkedAt: string;
};

type LinkedMember = {
    name: string;
    telegramUsername: string | null;
};

type LinkCode = {
    code: string;
    expiresAt: string;
};

type PageProps = {
    connected: boolean;
    botUsername: string | null;
    connectedAt: string | null;
    aiChatEnabled: boolean;
    hasAiProvider: boolean;
    myLink: LinkInfo | null;
    linkedMembers: LinkedMember[];
};

export default function TelegramEdit({
    connected,
    botUsername,
    connectedAt,
    aiChatEnabled,
    hasAiProvider,
    myLink,
    linkedMembers,
}: PageProps) {
    const [linkCode, setLinkCode] = useState<LinkCode | null>(null);

    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (
                event as CustomEvent<{ flash?: { linkCode?: LinkCode } }>
            ).detail?.flash;

            if (flash?.linkCode) {
                setLinkCode(flash.linkCode);
            }
        });
    }, []);

    return (
        <>
            <Head title="Telegram" />

            <SettingsShell>
                <Heading
                    variant="small"
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

                {connected && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <div>
                            <h2 className="font-medium">Your account</h2>
                            <p className="text-sm text-muted-foreground">
                                The bot only answers linked members. Link your
                                own Telegram account to use it.
                            </p>
                        </div>

                        {myLink ? (
                            <>
                                <p className="text-sm">
                                    Linked
                                    {myLink.telegramUsername
                                        ? ` as @${myLink.telegramUsername}`
                                        : ''}{' '}
                                    on{' '}
                                    {new Date(myLink.linkedAt).toLocaleString()}
                                    .
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.post(
                                            TelegramBotLinkController.test.url(),
                                        )
                                    }
                                >
                                    Send test message
                                </Button>
                            </>
                        ) : (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Not linked yet.
                                </p>
                                <Button
                                    type="button"
                                    onClick={() =>
                                        router.post(
                                            TelegramBotLinkController.store.url(),
                                        )
                                    }
                                >
                                    Get link code
                                </Button>
                            </>
                        )}

                        {linkCode && (
                            <div className="space-y-1 rounded-md border bg-muted/50 p-3">
                                <p className="text-sm">
                                    Send this to the bot on Telegram:
                                </p>
                                <p className="font-mono text-lg font-semibold">
                                    /link {linkCode.code}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Expires{' '}
                                    {new Date(
                                        linkCode.expiresAt,
                                    ).toLocaleTimeString()}
                                    .
                                </p>
                            </div>
                        )}

                        {linkedMembers.length > 0 && (
                            <div className="space-y-1 border-t pt-4">
                                <h3 className="text-sm font-medium">
                                    Linked members
                                </h3>
                                <ul className="space-y-1 text-sm text-muted-foreground">
                                    {linkedMembers.map((member) => (
                                        <li key={member.name}>
                                            {member.name}
                                            {member.telegramUsername
                                                ? ` (@${member.telegramUsername})`
                                                : ''}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}

                {connected && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <div className="flex items-center justify-between gap-4">
                            <div>
                                <h2 className="font-medium">AI chat</h2>
                                <p className="text-sm text-muted-foreground">
                                    When on, a plain message gets a
                                    conversational reply instead of being
                                    captured as a note. Links, photos, voice
                                    notes, and /note always still capture. The
                                    AI has no access to this app's data or any
                                    tools, it can only talk.
                                </p>
                            </div>
                            <Badge
                                variant={aiChatEnabled ? 'default' : 'outline'}
                            >
                                {aiChatEnabled ? 'On' : 'Off'}
                            </Badge>
                        </div>

                        {!hasAiProvider && (
                            <p className="text-sm text-muted-foreground">
                                No AI provider is configured yet. Add one under{' '}
                                <Link
                                    href={aiProvidersIndex()}
                                    className="underline underline-offset-4"
                                >
                                    Settings → AI Models
                                </Link>{' '}
                                before turning this on.
                            </p>
                        )}

                        <Form
                            {...TelegramBotConfigController.toggleAiChat.form()}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    {aiChatEnabled ? 'Turn off' : 'Turn on'}
                                </Button>
                            )}
                        </Form>
                    </div>
                )}
            </SettingsShell>
        </>
    );
}

TelegramEdit.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'Telegram', href: edit() },
    ],
};
