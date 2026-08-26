export const LANG_META = {
    bn: { code: 'bn', label: 'Bangla', flag: '🇧🇩' },
    en: { code: 'en', label: 'English', flag: '🇺🇸' },
} as const;

export type LangCode = keyof typeof LANG_META;
