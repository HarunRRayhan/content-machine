import {
    ArrowLeft,
    ExternalLink,
    File,
    FileImage,
    FileVideo,
    Folder,
    LoaderCircle,
    Search,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

export type GoogleDriveFile = {
    id: string;
    name: string;
    mime_type: string | null;
    is_folder: boolean;
    is_public: boolean;
    share_url: string | null;
    modified_time: string | null;
    size: number | null;
    parents: string[];
    web_view_link: string | null;
};

type CurrentFolder = {
    id: string;
    name: string;
    parent_id: string | null;
};

type FileResponse = {
    files?: GoogleDriveFile[];
    current_folder?: CurrentFolder;
    message?: string;
};

type Props = {
    kind: 'file' | 'folder';
    fileType?: 'video' | 'image';
    initialFolderId?: string | null;
    trigger: ReactElement;
    onSelect: (file: GoogleDriveFile) => void;
};

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

function formatSize(size: number | null): string {
    if (size === null || size < 1024) {
        return size === null ? '' : `${size} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = size / 1024;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit += 1;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
}

function formatDate(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? ''
        : new Intl.DateTimeFormat('en-GB', {
              dateStyle: 'medium',
          }).format(date);
}

function matchesFileType(
    file: GoogleDriveFile,
    fileType?: Props['fileType'],
): boolean {
    if (file.is_folder || !fileType) {
        return true;
    }

    if (fileType === 'video') {
        return file.mime_type?.startsWith('video/') ?? false;
    }

    return file.mime_type?.startsWith('image/') ?? false;
}

export default function GoogleDrivePicker({
    kind,
    fileType,
    initialFolderId,
    trigger,
    onSelect,
}: Props) {
    const [open, setOpen] = useState(false);
    const [folderId, setFolderId] = useState<string | null>(
        initialFolderId ?? null,
    );
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [files, setFiles] = useState<GoogleDriveFile[]>([]);
    const [currentFolder, setCurrentFolder] = useState<CurrentFolder | null>(
        null,
    );
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [publishingId, setPublishingId] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        const params = new URLSearchParams();

        if (folderId) {
            params.set('folder_id', folderId);
        }

        if (search) {
            params.set('q', search);
        }

        void fetch(`/settings/google-drive/files?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                const payload = (await response.json()) as FileResponse;

                if (!response.ok) {
                    throw new Error(
                        payload.message ?? 'Could not load Google Drive files.',
                    );
                }

                return payload;
            })
            .then((payload) => {
                setFiles(payload.files ?? []);
                setCurrentFolder(payload.current_folder ?? null);
            })
            .catch((reason: unknown) => {
                if (
                    reason instanceof DOMException &&
                    reason.name === 'AbortError'
                ) {
                    return;
                }

                setError(
                    reason instanceof Error
                        ? reason.message
                        : 'Could not load Google Drive files.',
                );
            })
            .finally(() => setLoading(false));

        return () => controller.abort();
    }, [folderId, open, search]);

    function handleOpenChange(nextOpen: boolean) {
        setOpen(nextOpen);

        if (nextOpen) {
            setLoading(true);
            setFolderId(initialFolderId ?? null);
            setSearchInput('');
            setSearch('');
            setError(null);
        }
    }

    function selectFolder(file: GoogleDriveFile) {
        if (!file.is_folder) {
            return;
        }

        onSelect(file);
        setOpen(false);
    }

    function navigateToFolder(nextFolderId: string | null) {
        setLoading(true);
        setError(null);
        setFolderId(nextFolderId);
    }

    function selectFile(file: GoogleDriveFile) {
        if (file.is_folder || !file.share_url) {
            return;
        }

        if (file.is_public) {
            onSelect(file);

            setOpen(false);

            return;
        }

        setPublishingId(file.id);
        setError(null);

        void fetch('/settings/google-drive/make-public', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({ file_id: file.id }),
        })
            .then(async (response) => {
                const payload = (await response.json()) as {
                    file?: GoogleDriveFile;
                    message?: string;
                };

                if (!response.ok || !payload.file) {
                    throw new Error(
                        payload.message ??
                            'Could not make this file publishable.',
                    );
                }

                onSelect(payload.file);
                setOpen(false);
            })
            .catch((reason: unknown) => {
                setError(
                    reason instanceof Error
                        ? reason.message
                        : 'Could not make this file publishable.',
                );
            })
            .finally(() => setPublishingId(null));
    }

    const visibleFiles = files.filter((file) =>
        matchesFileType(file, kind === 'folder' ? undefined : fileType),
    );
    const selectedFolder = currentFolder ?? {
        id: folderId ?? 'root',
        name: folderId ? 'Folder' : 'My Drive',
        parent_id: null,
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        {kind === 'folder'
                            ? 'Choose a content folder'
                            : `Choose ${fileType === 'image' ? 'a cover image' : 'a video'}`}
                    </DialogTitle>
                    <DialogDescription>
                        {kind === 'folder'
                            ? 'Pick the Drive folder Content Machine should open first.'
                            : 'Private files are made public only when you choose them for publishing.'}
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        disabled={!selectedFolder.parent_id || loading}
                        onClick={() =>
                            navigateToFolder(selectedFolder.parent_id)
                        }
                        aria-label="Go to parent folder"
                    >
                        <ArrowLeft />
                    </Button>
                    <div className="min-w-0 flex-1 truncate text-sm font-medium">
                        {selectedFolder.name}
                    </div>
                    {kind === 'folder' && (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() =>
                                selectFolder({
                                    id: selectedFolder.id,
                                    name: selectedFolder.name,
                                    mime_type:
                                        'application/vnd.google-apps.folder',
                                    is_folder: true,
                                    is_public: false,
                                    share_url: null,
                                    modified_time: null,
                                    size: null,
                                    parents: selectedFolder.parent_id
                                        ? [selectedFolder.parent_id]
                                        : [],
                                    web_view_link: null,
                                })
                            }
                        >
                            Use this folder
                        </Button>
                    )}
                </div>

                <form
                    className="flex gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        setLoading(true);
                        setError(null);
                        setSearch(searchInput.trim());
                    }}
                >
                    <Input
                        value={searchInput}
                        onChange={(event) => setSearchInput(event.target.value)}
                        placeholder="Search this folder"
                        aria-label="Search this folder"
                    />
                    <Button type="submit" variant="outline" size="icon">
                        <Search />
                        <span className="sr-only">Search</span>
                    </Button>
                </form>

                {error && (
                    <div className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                        {error}{' '}
                        <a className="underline" href="/settings/google-drive">
                            Open Drive settings
                        </a>
                    </div>
                )}

                <div className="max-h-[min(50vh,30rem)] overflow-y-auto rounded-md border">
                    {loading ? (
                        <div className="flex items-center justify-center gap-2 p-8 text-sm text-muted-foreground">
                            <LoaderCircle className="animate-spin" />
                            Loading Drive files...
                        </div>
                    ) : visibleFiles.length === 0 ? (
                        <p className="p-8 text-center text-sm text-muted-foreground">
                            No matching files in this folder.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {visibleFiles.map((file) => (
                                <li
                                    key={file.id}
                                    className="flex items-center gap-3 p-3"
                                >
                                    <span className="text-muted-foreground">
                                        {file.is_folder ? (
                                            <Folder />
                                        ) : file.mime_type?.startsWith(
                                              'video/',
                                          ) ? (
                                            <FileVideo />
                                        ) : file.mime_type?.startsWith(
                                              'image/',
                                          ) ? (
                                            <FileImage />
                                        ) : (
                                            <File />
                                        )}
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        {file.is_folder ? (
                                            <button
                                                type="button"
                                                className="truncate text-left text-sm font-medium hover:underline"
                                                onClick={() =>
                                                    navigateToFolder(file.id)
                                                }
                                            >
                                                {file.name}
                                            </button>
                                        ) : (
                                            <div className="truncate text-sm font-medium">
                                                {file.name}
                                            </div>
                                        )}
                                        <div className="truncate text-xs text-muted-foreground">
                                            {file.is_folder
                                                ? 'Folder'
                                                : [
                                                      formatSize(file.size),
                                                      formatDate(
                                                          file.modified_time,
                                                      ),
                                                  ]
                                                      .filter(Boolean)
                                                      .join(' · ')}
                                        </div>
                                    </div>
                                    {file.web_view_link && (
                                        <a
                                            href={file.web_view_link}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-muted-foreground hover:text-foreground"
                                            aria-label={`Open ${file.name} in Google Drive`}
                                        >
                                            <ExternalLink />
                                        </a>
                                    )}
                                    {kind === 'folder' && file.is_folder ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => selectFolder(file)}
                                        >
                                            Use
                                        </Button>
                                    ) : kind === 'file' && !file.is_folder ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant={
                                                file.is_public
                                                    ? 'outline'
                                                    : 'default'
                                            }
                                            disabled={publishingId !== null}
                                            onClick={() => selectFile(file)}
                                        >
                                            {publishingId === file.id
                                                ? 'Making public...'
                                                : file.is_public
                                                  ? 'Use file'
                                                  : 'Make public & use'}
                                        </Button>
                                    ) : null}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="ghost">
                            Cancel
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
