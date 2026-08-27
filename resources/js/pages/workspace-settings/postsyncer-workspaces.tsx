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
} from '@/components/workspace-settings/postsyncer-language-section';
import {
    languageLabel,
    PostsyncerLanguageSection,
} from '@/components/workspace-settings/postsyncer-language-section';
import { PostsyncerPageMenu } from '@/components/workspace-settings/postsyncer-page-menu';
import { SettingsShell } from '@/components/workspace-settings/settings-shell';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';
import {
    edit as editPostsyncer,
    workspaces as editWorkspaces,
} from '@/routes/settings/postsyncer';

const ALL_LANGUAGES = ['english', 'bangla'] as const;

type PostTypesConfig = {
    platforms?: Record<string, Record<string, string>>;
    overrides?: Record<string, Record<string, Record<string, string>>>;
};

type PageProps = {
    defaultLanguage: string;
    enabledLanguages: string[];
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

export default function PostsyncerWorkspaceSettings({
    defaultLanguage,
    enabledLanguages,
    availableWorkspaces,
    workspacesLoadError,
    languages,
    postTypes,
    platforms,
    postTypeNames,
    postTypeStates,
}: PageProps) {
    const [selectedDefault, setSelectedDefault] = useState(defaultLanguage);
    const [extras, setExtras] = useState(
        enabledLanguages.filter((language) => language !== defaultLanguage),
    );
    const [platformsByLanguage, setPlatformsByLanguage] = useState<
        Record<string, Record<string, PlatformEntry>>
    >({
        bangla: languages.bangla.platforms,
        english: languages.english.platforms,
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

    async function refreshAccounts(language: string) {
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
                setPlatformsByLanguage((current) => ({
                    ...current,
                    [language]: payload.suggested ?? {},
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
    }

    return (
        <>
            <Head title="PostSyncer workspaces" />

            <SettingsShell>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Workspaces"
                        description="Pick a default workspace, then add extras only if you post in more than one language."
                    />
                    <PostsyncerPageMenu active="workspaces" />
                </div>

                <div className="max-w-3xl space-y-6">
                    {workspacesLoadError && (
                        <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
                            Could not load workspaces: {workspacesLoadError}
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
                                        Default workspace
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
                                        key={`default-${selectedDefault}-${JSON.stringify(platformsByLanguage[selectedDefault])}`}
                                        language={selectedDefault}
                                        config={{
                                            workspace_id:
                                                languages[
                                                    selectedDefault as
                                                        'bangla' | 'english'
                                                ].workspace_id,
                                            platforms:
                                                platformsByLanguage[
                                                    selectedDefault
                                                ] ?? {},
                                        }}
                                        platforms={platforms}
                                        availableWorkspaces={
                                            availableWorkspaces
                                        }
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
                                            key={`extra-${language}-${JSON.stringify(platformsByLanguage[language])}`}
                                            language={language}
                                            config={{
                                                workspace_id:
                                                    languages[
                                                        language as
                                                            'bangla' | 'english'
                                                    ].workspace_id,
                                                platforms:
                                                    platformsByLanguage[
                                                        language
                                                    ] ?? {},
                                            }}
                                            platforms={platforms}
                                            availableWorkspaces={
                                                availableWorkspaces
                                            }
                                            onRefresh={refreshAccounts}
                                            refreshing={
                                                refreshingLanguage === language
                                            }
                                        />
                                    </div>
                                ))}

                                {unused.length > 0 && (
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
                                                Add {languageLabel(language)}{' '}
                                                workspace
                                            </Button>
                                        ))}
                                    </div>
                                )}

                                <div className="space-y-4 rounded-lg border p-4">
                                    <Heading
                                        variant="small"
                                        title="Post-type matrix"
                                        description="Platform support by content type."
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
                                                                            unset
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

                                <InputError message={errors.default_language} />

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

PostsyncerWorkspaceSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'PostSyncer', href: editPostsyncer() },
        { title: 'Workspaces', href: editWorkspaces() },
    ],
};
