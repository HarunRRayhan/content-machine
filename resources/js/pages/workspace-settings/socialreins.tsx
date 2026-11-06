import { Form, Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SettingsShell } from '@/components/workspace-settings/settings-shell';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';

type Language = { set_id: string | null; token_configured: boolean };

type PageProps = {
    driver: 'postsyncer' | 'socialreins';
    baseUrl: string;
    webhookUrl: string;
    webhookSecretConfigured: boolean;
    tiktokPrivacyLevel: string | null;
    privacyLevels: string[];
    defaultLanguage: string;
    languages: Record<string, Language>;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs';

export default function SocialreinsSettings({
    driver,
    baseUrl,
    webhookUrl,
    webhookSecretConfigured,
    tiktokPrivacyLevel,
    privacyLevels,
    defaultLanguage,
    languages,
}: PageProps) {
    return (
        <>
            <Head title="SocialReins" />
            <SettingsShell>
                <Heading
                    variant="small"
                    title="SocialReins"
                    description="Publish this workspace's posts and videos through SocialReins instead of PostSyncer. PostSyncer stays the default."
                />
                <div className="max-w-3xl space-y-6">
                    <Badge
                        variant={
                            driver === 'socialreins' ? 'default' : 'outline'
                        }
                    >
                        {driver === 'socialreins'
                            ? 'Publishing through SocialReins'
                            : 'Publishing through PostSyncer'}
                    </Badge>
                    <Form
                        action="/settings/socialreins"
                        method="put"
                        className="space-y-6"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="publish_driver">
                                        Publish driver
                                    </Label>
                                    <select
                                        id="publish_driver"
                                        name="publish_driver"
                                        defaultValue={driver}
                                        className={selectClass}
                                    >
                                        <option value="postsyncer">
                                            PostSyncer (default)
                                        </option>
                                        <option value="socialreins">
                                            SocialReins
                                        </option>
                                    </select>
                                    <InputError
                                        message={errors.publish_driver}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="base_url">
                                        API base URL
                                    </Label>
                                    <Input
                                        id="base_url"
                                        name="base_url"
                                        defaultValue={baseUrl}
                                    />
                                    <InputError message={errors.base_url} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="default_language">
                                        Default language
                                    </Label>
                                    <select
                                        id="default_language"
                                        name="default_language"
                                        defaultValue={defaultLanguage}
                                        className={selectClass}
                                    >
                                        {Object.keys(languages).map(
                                            (language) => (
                                                <option
                                                    key={language}
                                                    value={language}
                                                >
                                                    {language}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    <InputError
                                        message={errors.default_language}
                                    />
                                </div>
                                {Object.entries(languages).map(
                                    ([language, values]) => (
                                        <fieldset
                                            key={language}
                                            className="grid gap-4 rounded-md border p-4"
                                        >
                                            <legend className="px-1 text-sm font-medium capitalize">
                                                {language}
                                            </legend>
                                            <div className="grid gap-2">
                                                <Label
                                                    htmlFor={`set_${language}`}
                                                >
                                                    Set id
                                                </Label>
                                                <Input
                                                    id={`set_${language}`}
                                                    name={`languages[${language}][set_id]`}
                                                    defaultValue={
                                                        values.set_id ?? ''
                                                    }
                                                    placeholder="SocialReins set UUID"
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `languages.${language}.set_id`
                                                        ]
                                                    }
                                                />
                                            </div>
                                            <div className="grid gap-2">
                                                <Label
                                                    htmlFor={`token_${language}`}
                                                >
                                                    Set token{' '}
                                                    {values.token_configured &&
                                                        '(saved)'}
                                                </Label>
                                                <Input
                                                    id={`token_${language}`}
                                                    name={`languages[${language}][token]`}
                                                    type="password"
                                                    autoComplete="new-password"
                                                    placeholder={
                                                        values.token_configured
                                                            ? 'Paste a replacement token'
                                                            : 'sr_set_live_...'
                                                    }
                                                />
                                                <InputError
                                                    message={
                                                        errors[
                                                            `languages.${language}.token`
                                                        ]
                                                    }
                                                />
                                                <p className="text-sm text-muted-foreground">
                                                    Limited to this language's
                                                    set. Encrypted, never shown
                                                    again.
                                                </p>
                                            </div>
                                        </fieldset>
                                    ),
                                )}
                                <div className="grid gap-2">
                                    <Label htmlFor="tiktok_privacy_level">
                                        TikTok visibility
                                    </Label>
                                    <select
                                        id="tiktok_privacy_level"
                                        name="tiktok_privacy_level"
                                        defaultValue={tiktokPrivacyLevel ?? ''}
                                        className={selectClass}
                                    >
                                        <option value="">
                                            Not chosen (TikTok posts are
                                            refused)
                                        </option>
                                        {privacyLevels.map((level) => (
                                            <option key={level} value={level}>
                                                {level}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.tiktok_privacy_level}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        TikTok requires an explicit visibility.
                                        It is never defaulted to public.
                                    </p>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="webhook_secret">
                                        Webhook secret{' '}
                                        {webhookSecretConfigured && '(saved)'}
                                    </Label>
                                    <Input
                                        id="webhook_secret"
                                        name="webhook_secret"
                                        type="password"
                                        autoComplete="new-password"
                                        placeholder="whsec_..."
                                    />
                                    <InputError
                                        message={errors.webhook_secret}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        Set the SocialReins team webhook to{' '}
                                        <code>{webhookUrl}</code> and paste its
                                        secret here. Without it CM falls back to
                                        polling every five minutes.
                                    </p>
                                </div>
                                <Button disabled={processing} type="submit">
                                    Save
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </SettingsShell>
        </>
    );
}

SocialreinsSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'SocialReins', href: '/settings/socialreins' },
    ],
};
