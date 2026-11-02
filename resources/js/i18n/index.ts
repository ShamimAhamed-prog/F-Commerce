import { createI18n } from 'vue-i18n';
import en from './en';
import bn from './bn';

/** NFR-5: all UI strings go through i18n keys from day one. */
export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en, bn },
});
