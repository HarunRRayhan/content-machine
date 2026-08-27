import { Form, Head } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import PostsyncerSettingsController from '@/actions/App/Http/Controllers/Settings/PostsyncerSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SettingsTabs } from '@/components/workspace-settings/settings-tabs';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';
import { edit as editPostsyncer } from '@/routes/settings/postsyncer';

type PlatformEntry = {
    account_id: string | number | null;
    handle: string;
};

type LanguageConfig = {
    workspace_id: string | null;
    platforms: Record<string, PlatformEntry>;
};

type PostTypesConfig = {
    platforms?: Record<string, Record<string, string>>;
    overrides?: Record<string, Record<string, Record<string, string>>>;
};

type AvailableWorkspace = {
    id: string;
    label: string;
};

type PageProps = {
    apiKeyConfigured: boolean;
    apiBase: string;
    uploadBase: string;
    publishEnabled: boolean;
    availableWorkspaces: AvailableWorkspace[];
    workspacesLoadError: string | null;
    languages: {
        bangla: LanguageConfig;
        english: LanguageConfig;
    };
    postTypes: PostTypesConfig;
    platforms: string[];
    postTypeNames: string[];
    postTypeStates: string[];
};

function platformLabel(platform: string): string {
    return platform.charAt(0).toUpperCase() + platform.slice(1);
}

function LanguageSection({
    language,
    label,
    config,
    platforms,
    availableWorkspaces,
    onRefresh,
    refreshing,
}: {
    language: 'bangla' | 'english';
    label: string;
    config: LanguageConfig;
    platforms: string[];
    availableWorkspaces: AvailableWorkspace[];
    onRefresh: (language: 'bangla' | 'english') => void;
    refreshing: boolean;
}) {
    return (
        <div className="space-y-4 rounded-lg border p-4">
            <div className="flex items-center justify-between gap-4">
                <Heading variant="small" title={label} />
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={refreshing}
                    onClick={() => onRefresh(language)}
                >
                    <RefreshCw
                        className={`mr-2 size-4 ${refreshing ? 'animate-spin' : ''}`}
                    />
                    Refresh accounts
                </Button>
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${language}-workspace-id`}>
                    PostSyncer workspace
                </Label>
                <select
                    id={`${language}-workspace-id`}
                    name={`languages[${language}][workspace_id]`}
                    defaultValue={config.workspace_id ?? ''}
                    className="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm"
                >
                    <option value="">Select a workspace</option>
                    {availableWorkspaces.map((workspace) => (
                        <option key={workspace.id} value={workspace.id}>
                            {workspace.label === workspace.id
                                ? workspace.id
                                : `${workspace.label} (${workspace.id})`}
                        </option>
                    ))}
                    {config.workspace_id &&
                        !availableWorkspaces.some(
                            (workspace) => workspace.id === config.workspace_id,
                        ) && (
                            <option value={config.workspace_id}>
                                {config.workspace_id} (saved)
                            </option>
                        )}
                </select>
            </div>

            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b text-left">
                            <th className="py-2 pr-4 font-medium">Platform</th>
                            <th className="py-2 pr-4 font-medium">
                                Account id
                            </th>
                            <th className="py-2 font-medium">Handle</th>
                        </tr>
                    </thead>
                    <tbody>
                        {platforms.map((platform) => (
                            <tr
                                key={platform}
                                className="border-b last:border-0"
                            >
                                <td className="py-2 pr-4">
                                    {platformLabel(platform)}
                                </td>
                                <td className="py-2 pr-4">
                                    <Input
                                        name={`languages[${language}][platforms][${platform}][account_id]`}
                                        defaultValue={
                                            config.platforms[platform]
                                                ?.account_id ?? ''
                                        }
                                        placeholder="Account id"
                                    />
                                </td>
                                <td className="py-2">
                                    <Input
                                        name={`languages[${language}][platforms][${platform}][handle]`}
                                        defaultValue={
                                            config.platforms[platform]
                                                ?.handle ?? ''
                                        }
                                        placeholder="@handle"
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}

export default function PostsyncerSettings({
    apiKeyConfigured,
    apiBase,
    uploadBase,
    publishEnabled,
    availableWorkspaces,
    workspacesLoadError,
    languages,
    postTypes,
    platforms,
    postTypeNames,
    postTypeStates,
}: PageProps) {
    const [banglaPlatforms, setBanglaPlatforms] = useState(
        languages.bangla.platforms,
    );
    const [englishPlatforms, setEnglishPlatforms] = useState(
        languages.english.platforms,
    );
    const [refreshingLanguage, setRefreshingLanguage] = useState<
        'bangla' | 'english' | null
    >(null);

    async function refreshAccounts(language: 'bangla' | 'english') {
        setRefreshingLanguage(language);

        try {
            const response = await fetch(
                PostsyncerSettingsController.refreshAccounts.url(),
                {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') ?? '',
                    },
                    body: JSON.stringify({ language }),
                },
            );

            const payload = (await response.json()) as {
                suggested?: Record<string, PlatformEntry>;
                message?: string;
            };

            if (!response.ok) {
                window.alert(payload.message ?? 'Could not refresh accounts.');

                return;
            }

            if (payload.suggested) {
                if (language === 'bangla') {
                    setBanglaPlatforms(payload.suggested);
                } else {
                    setEnglishPlatforms(payload.suggested);
                }
            }
        } finally {
            setRefreshingLanguage(null);
        }
    }

    const languagesForForm = {
        bangla: { ...languages.bangla, platforms: banglaPlatforms },
        english: { ...languages.english, platforms: englishPlatforms },
    };

    return (
        <>
            <Head title="Settings" />

            <div className="studio-page flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Heading
                    title="Settings"
                    description="Workspace settings for Content Machine."
                />

                <SettingsTabs active="postsyncer" />

                <div className="max-w-3xl space-y-6">
                    <Heading
                        variant="small"
                        title="PostSyncer"
                        description="Connect PostSyncer for scheduling and publishing posts and videos."
                    />

                    <div className="flex items-center gap-2">
                        <Badge
                            variant={apiKeyConfigured ? 'default' : 'outline'}
                        >
                            {apiKeyConfigured
                                ? 'API key configured'
                                : 'No API key'}
                        </Badge>
                    </div>

                    <Form
                        {...PostsyncerSettingsController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-8"
                    >
                        {({ processing, errors }) => (
                            <>
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

                                {!apiKeyConfigured && (
                                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                        Save an API key first. PostSyncer
                                        workspaces load automatically after the
                                        key is saved.
                                    </p>
                                )}

                                {apiKeyConfigured && workspacesLoadError && (
                                    <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
                                        Could not load workspaces:{' '}
                                        {workspacesLoadError}
                                    </p>
                                )}

                                {apiKeyConfigured && (
                                    <>
                                        <LanguageSection
                                            key={`bangla-${JSON.stringify(banglaPlatforms)}`}
                                            language="bangla"
                                            label="Bangla"
                                            config={languagesForForm.bangla}
                                            platforms={platforms}
                                            availableWorkspaces={
                                                availableWorkspaces
                                            }
                                            onRefresh={refreshAccounts}
                                            refreshing={
                                                refreshingLanguage === 'bangla'
                                            }
                                        />

                                        <LanguageSection
                                            key={`english-${JSON.stringify(englishPlatforms)}`}
                                            language="english"
                                            label="English"
                                            config={languagesForForm.english}
                                            platforms={platforms}
                                            availableWorkspaces={
                                                availableWorkspaces
                                            }
                                            onRefresh={refreshAccounts}
                                            refreshing={
                                                refreshingLanguage === 'english'
                                            }
                                        />
                                    </>
                                )}

                                <div className="space-y-4 rounded-lg border p-4">
                                    <Heading
                                        variant="small"
                                        title="Post-type matrix"
                                        description="Platform support by content type. Overrides apply per language."
                                    />

                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b text-left">
                                                    <th className="py-2 pr-4 font-medium">
                                                        Platform
                                                    </th>
                                                    {postTypeNames.map(
                                                        (type) => (
                                                            <th
                                                                key={type}
                                                                className="py-2 pr-4 font-medium capitalize"
                                                            >
                                                                {type}
                                                            </th>
                                                        ),
                                                    )}
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {platforms.map((platform) => (
                                                    <tr
                                                        key={platform}
                                                        className="border-b last:border-0"
                                                    >
                                                        <td className="py-2 pr-4">
                                                            {platformLabel(
                                                                platform,
                                                            )}
                                                        </td>
                                                        {postTypeNames.map(
                                                            (type) => (
                                                                <td
                                                                    key={type}
                                                                    className="py-2 pr-4"
                                                                >
                                                                    <select
                                                                        name={`post_types[platforms][${platform}][${type}]`}
                                                                        defaultValue={
                                                                            postTypes
                                                                                .platforms?.[
                                                                                platform
                                                                            ]?.[
                                                                                type
                                                                            ] ??
                                                                            ''
                                                                        }
                                                                        className="h-9 w-full min-w-24 rounded-md border border-input bg-transparent px-2 text-sm"
                                                                    >
                                                                        <option value="">
                                                                            —
                                                                        </option>
                                                                        {postTypeStates.map(
                                                                            (
                                                                                state,
                                                                            ) => (
                                                                                <option
                                                                                    key={
                                                                                        state
                                                                                    }
                                                                                    value={
                                                                                        state
                                                                                    }
                                                                                >
                                                                                    {
                                                                                        state
                                                                                    }
                                                                                </option>
                                                                            ),
                                                                        )}
                                                                    </select>
                                                                </td>
                                                            ),
                                                        )}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div className="flex items-center gap-4">
                                    <Button disabled={processing} type="submit">
                                        Save
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </div>
            </div>
        </>
    );
}

PostsyncerSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'PostSyncer', href: editPostsyncer() },
    ],
};
