import { Form, Head, Link } from '@inertiajs/react';
import PostsyncerSettingsController from '@/actions/App/Http/Controllers/Settings/PostsyncerSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PostsyncerTabs } from '@/components/workspace-settings/postsyncer-tabs';
import { SettingsShell } from '@/components/workspace-settings/settings-shell';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';
import {
    edit as editPostsyncer,
    workspaces as editWorkspaces,
} from '@/routes/settings/postsyncer';

type PageProps = {
    apiKeyConfigured: boolean;
    apiBase: string;
    uploadBase: string;
    publishEnabled: boolean;
};

export default function PostsyncerApiSettings({
    apiKeyConfigured,
    apiBase,
    uploadBase,
    publishEnabled,
}: PageProps) {
    return (
        <>
            <Head title="PostSyncer" />

            <SettingsShell>
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="PostSyncer"
                        description="Connect the API first. Workspaces and social accounts come next."
                    />
                    <PostsyncerTabs active="general" />
                </div>

                <div className="max-w-3xl space-y-6">
                    <Badge variant={apiKeyConfigured ? 'default' : 'outline'}>
                        {apiKeyConfigured ? 'API key configured' : 'No API key'}
                    </Badge>

                    <Form
                        {...PostsyncerSettingsController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-8"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input type="hidden" name="page" value="api" />

                                <div className="grid gap-2">
                                    <Label htmlFor="api_key">API key</Label>
                                    <Input
                                        id="api_key"
                                        name="api_key"
                                        type="password"
                                        autoComplete="off"
                                        placeholder={
                                            apiKeyConfigured
                                                ? 'Leave blank to keep the current key'
                                                : 'Paste your PostSyncer API key'
                                        }
                                    />
                                    <InputError message={errors.api_key} />
                                </div>

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="grid gap-2">
                                        <Label htmlFor="api_base">
                                            API base URL
                                        </Label>
                                        <Input
                                            id="api_base"
                                            name="api_base"
                                            defaultValue={apiBase}
                                        />
                                        <InputError message={errors.api_base} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="upload_base">
                                            Upload base URL
                                        </Label>
                                        <Input
                                            id="upload_base"
                                            name="upload_base"
                                            defaultValue={uploadBase}
                                        />
                                        <InputError
                                            message={errors.upload_base}
                                        />
                                    </div>
                                </div>

                                <input
                                    type="hidden"
                                    name="publish_enabled"
                                    value="0"
                                />
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="publish_enabled"
                                        value="1"
                                        defaultChecked={publishEnabled}
                                        className="size-4 rounded border-input"
                                    />
                                    Enable publishing from Content Machine
                                </label>
                                <InputError message={errors.publish_enabled} />

                                <div className="flex flex-wrap items-center gap-4">
                                    <Button disabled={processing} type="submit">
                                        Save
                                    </Button>
                                    {apiKeyConfigured && (
                                        <Link
                                            href={editWorkspaces()}
                                            className="text-sm underline"
                                        >
                                            Continue to workspaces
                                        </Link>
                                    )}
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </SettingsShell>
        </>
    );
}

PostsyncerApiSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'PostSyncer', href: editPostsyncer() },
    ],
};
