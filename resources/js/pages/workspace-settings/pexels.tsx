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

type PageProps = { apiKeyConfigured: boolean };

export default function PexelsSettings({ apiKeyConfigured }: PageProps) {
    return (
        <>
            <Head title="Pexels" />
            <SettingsShell>
                <Heading
                    variant="small"
                    title="Pexels"
                    description="Search and import stock photos into this workspace's media library."
                />
                <div className="max-w-3xl space-y-6">
                    <Badge variant={apiKeyConfigured ? 'default' : 'outline'}>
                        {apiKeyConfigured ? 'API key configured' : 'No API key'}
                    </Badge>
                    <Form
                        action="/settings/pexels"
                        method="post"
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="api_key">
                                        Pexels API key
                                    </Label>
                                    <Input
                                        id="api_key"
                                        name="api_key"
                                        type="password"
                                        autoComplete="new-password"
                                        placeholder={
                                            apiKeyConfigured
                                                ? 'Paste a replacement key'
                                                : 'Paste your Pexels API key'
                                        }
                                    />
                                    <InputError message={errors.api_key} />
                                    <p className="text-sm text-muted-foreground">
                                        The key is encrypted in workspace
                                        settings and never returned to clients.
                                    </p>
                                </div>
                                <Button disabled={processing} type="submit">
                                    Save API key
                                </Button>
                            </>
                        )}
                    </Form>
                </div>
            </SettingsShell>
        </>
    );
}

PexelsSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'Pexels', href: '/settings/pexels' },
    ],
};
