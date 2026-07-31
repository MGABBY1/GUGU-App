import { createContext, useContext, useState, useCallback, useMemo, ReactNode } from 'react';
import { Lang, LANG_META, TranslationKey, translate, BRAND_NAME, APP_NAME } from './translations';
import {
  formatPriceLocalized,
  formatTimeAgoLocalized,
  localizeCategory,
  localizeProvince,
} from './format';

const STORAGE_KEY = 'gugu_lang';

function readLang(): Lang {
  const saved = localStorage.getItem(STORAGE_KEY) as Lang | null;
  if (saved === 'rw' || saved === 'en' || saved === 'fr') return saved;
  return 'rw';
}

type LangCtx = {
  lang: Lang;
  setLang: (l: Lang) => void;
  t: (key: TranslationKey) => string;
  brand: string;
  appName: string;
  price: (amount: number, isFree?: boolean | number) => string;
  timeAgo: (date?: string | null) => string;
  category: (nameRw?: string | null, nameEn?: string | null, fallback?: string | null) => string;
  province: (name: string) => string;
};

const LanguageContext = createContext<LangCtx>({
  lang: 'rw',
  setLang: () => {},
  t: (k) => translate('rw', k),
  brand: BRAND_NAME,
  appName: APP_NAME,
  price: (n, free) => formatPriceLocalized(n, !!free, 'rw'),
  timeAgo: (d) => formatTimeAgoLocalized(d, 'rw'),
  category: (rw, en, fb) => localizeCategory('rw', rw, en, fb),
  province: (n) => localizeProvince(n, 'rw'),
});

export function LanguageProvider({ children }: { children: ReactNode }) {
  const [lang, setLangState] = useState<Lang>(readLang);

  const setLang = useCallback((l: Lang) => {
    setLangState(l);
    localStorage.setItem(STORAGE_KEY, l);
    document.documentElement.lang = l === 'rw' ? 'rw' : l;
  }, []);

  const value = useMemo<LangCtx>(() => ({
    lang,
    setLang,
    t: (key) => translate(lang, key),
    brand: BRAND_NAME,
    appName: APP_NAME,
    price: (amount, isFree) => formatPriceLocalized(amount, !!isFree, lang),
    timeAgo: (date) => formatTimeAgoLocalized(date, lang),
    category: (rw, en, fb) => localizeCategory(lang, rw, en, fb),
    province: (name) => localizeProvince(name, lang),
  }), [lang, setLang]);

  return (
    <LanguageContext.Provider value={value}>
      {children}
    </LanguageContext.Provider>
  );
}

export function useLang() {
  return useContext(LanguageContext);
}

/** Compact language switcher (EN / FR / RW) */
export function LanguageSwitcher({ compact = false }: { compact?: boolean }) {
  const { lang, setLang, t } = useLang();

  return (
    <label className={`lang-switch${compact ? ' lang-switch-compact' : ''}`} title={t('language')}>
      {!compact && <span className="lang-switch-label">🌐 {t('language')}</span>}
      <select
        className="lang-switch-select"
        value={lang}
        onChange={e => setLang(e.target.value as Lang)}
        aria-label={t('language')}
      >
        {(Object.keys(LANG_META) as Lang[]).map(code => (
          <option key={code} value={code}>
            {compact ? LANG_META[code].short : `${LANG_META[code].short} · ${LANG_META[code].label}`}
          </option>
        ))}
      </select>
    </label>
  );
}
