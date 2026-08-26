export const LANG_META = {
    bn: { code: 'bn', label: 'বাংলা', flag: '🇧🇩' },
    en: { code: 'en', label: 'English', flag: '🇺🇸' },
} as const;

export type LangCode = keyof typeof LANG_META;

/** Captions switcher: English first, Bangla second. */
export const LANG_SWITCHER_ORDER: LangCode[] = ['en', 'bn'];

export function orderLangs(langs: LangCode[]): LangCode[] {
    const unique = [...new Set(langs)];

    return [
        ...LANG_SWITCHER_ORDER.filter((lang) => unique.includes(lang)),
        ...unique.filter((lang) => !LANG_SWITCHER_ORDER.includes(lang)),
    ];
}
