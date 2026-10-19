import './page/canary-overview';
import enGB from '../../snippet/en-GB.json';
import deDE from '../../snippet/de-DE.json';

const { Module } = Shopware;

Module.register('yorvolabs-canary', {
    type: 'plugin',
    name: 'yorvoLabsCanary.general.name',
    title: 'yorvoLabsCanary.general.name',
    description: 'yorvoLabsCanary.general.description',
    color: '#ff6900',
    icon: 'regular-cog',

    snippets: {
        'en-GB': enGB,
        'de-DE': deDE,
    },

    routes: {
        overview: {
            component: 'canary-overview',
            path: 'overview',
        },
    },

    navigation: [{
        label: 'yorvoLabsCanary.general.name',
        color: '#ff6900',
        path: 'yorvolabs.canary.overview',
        icon: 'regular-cog',
        parent: 'sw-settings',
        position: 90,
    }],
});
