import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import PostsyncerSettingsController from '@/actions/App/Http/Controllers/Settings/PostsyncerSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type {
    AvailableWorkspace,
    LanguageConfig,
    PlatformEntry,
    PostTypesConfig,
} from '@/components/workspace-settings/postsyncer-language-section';
import {
    languageLabel,
    platformsFromAccounts,
    PostsyncerLanguageSection,
} from '@/components/workspace-settings/postsyncer-language-section';
import { PostsyncerTabs } from '@/components/workspace-settings/postsyncer-tabs';
import { SettingsShell } from '@/components/workspace-settings/settings-shell';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';
import {
    edit as editPostsyncer,
    workspaces as editWorkspaces,
} from '@/routes/settings/postsyncer';

const ALL_LANGUAGES = ['english', 'bangla'] as const;

type PageProps = {
    defaultLanguage: string;
    enabledLanguages: string[];
    availableWorkspaces: AvailableWorkspace[];
    workspacesLoadError: string | null;
    postsyncerConnected: boolean;
    languages: {
        bangla: LanguageConfig;
        english: LanguageConfig;
    };
    postTypes: PostTypesConfig;
    platforms: string[];
    postTypeNames: string[];
    postTypeStates: string[];
};

function withEnabled(
    platformsByName: Record<string, PlatformEntry>,
): Record<string, PlatformEntry> {
    return Object.fromEntries(
        Object.entries(platformsByName).map(([platform, entry]) => [
            platform,
            {
                account_id: entry.account_id,
                handle: entry.handle,
                enabled: entry.enabled ?? Boolean(entry.account_id),
            },
        ]),
    );
}

export default function PostsyncerWorkspaceSettings({
    defaultLanguage,
    enabledLanguages,
    availableWorkspaces,
    workspacesLoadError,
    postsyncerConnected,
    languages,
    postTypes,
    platforms,
    postTypeNames,
}: PageProps) {
    const [selectedDefault, setSelectedDefault] = useState(defaultLanguage);
    const [extras, setExtras] = useState(
        enabledLanguages.filter((language) => language !== defaultLanguage),
    );
    const [workspaceIds, setWorkspaceIds] = useState<Record<string, string>>({
        bangla: languages.bangla.workspace_id ?? '',
        english: languages.english.workspace_id ?? '',
    });
    const [platformsByLanguage, setPlatformsByLanguage] = useState<
        Record<string, Record<string, PlatformEntry>>
    >({
        bangla: withEnabled(languages.bangla.platforms),
        english: withEnabled(languages.english.platforms),
    });
    const [refreshingLanguage, setRefreshingLanguage] = useState<string | null>(
        null,
    );

    const enabled = [
        selectedDefault,
        ...extras.filter((language) => language !== selectedDefault),
    ];
    const unused = ALL_LANGUAGES.filter(
        (language) => !enabled.includes(language),
    );

    function applyWorkspace(language: string, workspaceId: string) {
        setWorkspaceIds((current) => ({
            ...current,
            [language]: workspaceId,
        }));

        const workspace = availableWorkspaces.find(
            (item) => item.id === workspaceId,
        );

        if (workspace && workspace.accounts.length > 0) {
            setPlatformsByLanguage((current) => ({
                ...current,
                [language]: platformsFromAccounts(
                    platforms,
                    workspace.accounts,
                ),
            }));

            return;
        }

        if (workspaceId !== '') {
            void refreshAccounts(language, workspaceId);

            return;
        }

        setPlatformsByLanguage((current) => ({
            ...current,
            [language]: withEnabled({}),
        }));
    }

    function changePlatform(
        language: string,
        platform: string,
        patch: Partial<PlatformEntry>,
    ) {
        setPlatformsByLanguage((current) => ({
            ...current,
            [language]: {
                ...current[language],
                [platform]: {
                    ...(current[language]?.[platform] ?? {
                        account_id: '',
                        handle: '',
                        enabled: false,
                    }),
                    ...patch,
                },
            },
        }));
    }

    async function refreshAccounts(language: string, workspaceId: string) {
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
                    body: JSON.stringify({
                        language,
                        workspace_id: workspaceId,
                    }),
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
                setPlatformsByLanguage((current) => ({
                    ...current,
                    [language]: withEnabled(payload.suggested ?? {}),
                }));
            }
        } finally {
            setRefreshingLanguage(null);
        }
    }

    function addExtra(language: string) {
        setExtras((current) =>
            current.includes(language) ? current : [...current, language],
        );
    }

    function removeExtra(language: string) {
        setExtras((current) => current.filter((item) => item !== language));
        setWorkspaceIds((current) => ({ ...current, [language]: '' }));
    }

    return (
        <>
            <Head title="PostSyncer workspaces" />

            <SettingsShell>
                <div className="space-y-4">
                    <Heading
                        variant="small"
                        title="PostSyncer"
                        description="Pick a workspace. We pull the handles from there, then you choose which ones stay on."
                    />
                    <PostsyncerTabs active="workspaces" />
                </div>

                <div className="max-w-3xl space-y-6">
                    {workspacesLoadError ? (
                        <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
                            PostSyncer is not connected: {workspacesLoadError}
                        </p>
                    ) : postsyncerConnected ? (
                        <p className="rounded-lg border border-emerald-600/30 bg-emerald-500/10 p-4 text-sm text-emerald-900 dark:text-emerald-200">
                            PostSyncer is connected.{' '}
                            {availableWorkspaces.length} workspace
                            {availableWorkspaces.length === 1 ? '' : 's'}{' '}
                            loaded.
                        </p>
                    ) : (
                        <p className="rounded-lg border border-amber-600/30 bg-amber-500/10 p-4 text-sm">
                            API key is saved, but no PostSyncer workspaces came
                            back. Check the key on General.
                        </p>
                    )}

                    <Form
                        {...PostsyncerSettingsController.update.form()}
                        options={{ preserveScroll: true }}
                        className="space-y-8"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="page"
                                    value="workspaces"
                                />
                                <input
                                    type="hidden"
                                    name="default_language"
                                    value={selectedDefault}
                                />
                                {enabled.map((language) => (
                                    <input
                                        key={language}
                                        type="hidden"
                                        name="enabled_languages[]"
                                        value={language}
                                    />
                                ))}
                                {ALL_LANGUAGES.filter(
                                    (language) => !enabled.includes(language),
                                ).map((language) => (
                                    <input
                                        key={`clear-${language}`}
                                        type="hidden"
                                        name={`languages[${language}][workspace_id]`}
                                        value=""
                                    />
                                ))}

                                <div className="grid gap-2">
                                    <Label htmlFor="default-language">
                                        Default language
                                    </Label>
                                    <select
                                        id="default-language"
                                        value={selectedDefault}
                                        onChange={(event) => {
                                            const next = event.target.value;
                                            setExtras((current) => {
                                                const withoutNext =
                                                    current.filter(
                                                        (language) =>
                                                            language !== next,
                                                    );

                                                if (
                                                    selectedDefault !== next &&
                                                    !withoutNext.includes(
                                                        selectedDefault,
                                                    )
                                                ) {
                                                    return [
                                                        selectedDefault,
                                                        ...withoutNext,
                                                    ];
                                                }

                                                return withoutNext;
                                            });
                                            setSelectedDefault(next);
                                        }}
                                        className="h-9 w-full max-w-xs rounded-md border border-input bg-transparent px-2 text-sm"
                                    >
                                        {ALL_LANGUAGES.map((language) => (
                                            <option
                                                key={language}
                                                value={language}
                                            >
                                                {languageLabel(language)}
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <div className="space-y-4 rounded-lg border p-4">
                                    <PostsyncerLanguageSection
                                        language={selectedDefault}
                                        workspaceId={
                                            workspaceIds[selectedDefault] ?? ''
                                        }
                                        platformsByName={
                                            platformsByLanguage[
                                                selectedDefault
                                            ] ?? {}
                                        }
                                        platforms={platforms}
                                        availableWorkspaces={
                                            availableWorkspaces
                                        }
                                        postTypes={postTypes}
                                        postTypeNames={postTypeNames}
                                        onWorkspaceChange={applyWorkspace}
                                        onPlatformChange={changePlatform}
                                        onRefresh={refreshAccounts}
                                        refreshing={
                                            refreshingLanguage ===
                                            selectedDefault
                                        }
                                    />
                                </div>

                                {extras.map((language) => (
                                    <div
                                        key={language}
                                        className="space-y-4 rounded-lg border p-4"
                                    >
                                        <div className="flex justify-end">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    removeExtra(language)
                                                }
                                            >
                                                Remove extra
                                            </Button>
                                        </div>
                                        <PostsyncerLanguageSection
                                            language={language}
                                            workspaceId={
                                                workspaceIds[language] ?? ''
                                            }
                                            platformsByName={
                                                platformsByLanguage[language] ??
                                                {}
                                            }
                                            platforms={platforms}
                                            availableWorkspaces={
                                                availableWorkspaces
                                            }
                                            postTypes={postTypes}
                                            postTypeNames={postTypeNames}
                                            onWorkspaceChange={applyWorkspace}
                                            onPlatformChange={changePlatform}
                                            onRefresh={refreshAccounts}
                                            refreshing={
                                                refreshingLanguage === language
                                            }
                                        />
                                    </div>
                                ))}

                                {unused.length > 0 &&
                                    (workspaceIds[selectedDefault] ?? '') !==
                                        '' && (
                                        <div className="flex flex-wrap items-center gap-2">
                                            {unused.map((language) => (
                                                <Button
                                                    key={language}
                                                    type="button"
                                                    variant="outline"
                                                    onClick={() =>
                                                        addExtra(language)
                                                    }
                                                >
                                                    Add{' '}
                                                    {languageLabel(language)}{' '}
                                                    workspace
                                                </Button>
                                            ))}
                                        </div>
                                    )}

                                <div className="hidden">
                                    {Object.entries(
                                        postTypes.overrides ?? {},
                                    ).flatMap(([language, byPlatform]) =>
                                        Object.entries(byPlatform).flatMap(
                                            ([platform, byType]) =>
                                                Object.entries(byType).map(
                                                    ([type, state]) => (
                                                        <input
                                                            key={`${language}-${platform}-${type}`}
                                                            type="hidden"
                                                            name={`post_types[overrides][${language}][${platform}][${type}]`}
                                                            value={state ?? ''}
                                                        />
                                                    ),
                                                ),
                                        ),
                                    )}
                                    {platforms.flatMap((platform) =>
                                        postTypeNames.map((type) => (
                                            <input
                                                key={`${platform}-${type}`}
                                                type="hidden"
                                                name={`post_types[platforms][${platform}][${type}]`}
                                                value={
                                                    postTypes.platforms?.[
                                                        platform
                                                    ]?.[type] ?? ''
                                                }
                                            />
                                        )),
                                    )}
                                </div>

                                <InputError message={errors.default_language} />

                                {(workspaceIds[selectedDefault] ?? '') !==
                                    '' && (
                                    <Button disabled={processing} type="submit">
                                        Save
                                    </Button>
                                )}
                            </>
                        )}
                    </Form>
                </div>
            </SettingsShell>
        </>
    );
}

PostsyncerWorkspaceSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'PostSyncer', href: editPostsyncer() },
        { title: 'Workspaces', href: editWorkspaces() },
    ],
};
