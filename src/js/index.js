import componentStore from '@/Store'
import DOM from "@/dom/dom";
import Component from "@/Component/index";
import Connection from '@/connection'
import drivers from '@/connection/drivers'
import { ArrayFlat, ArrayFrom, ArrayIncludes, ElementGetAttributeNames } from '@/dom/polyfills';
import 'whatwg-fetch'
import 'promise-polyfill/src/polyfill';
import { dispatch } from './util';
import LoadingStates from '@/component/LoadingStates'
import DirtyStates from '@/component/DirtyStates'
import OfflineStates from '@/component/OfflineStates'
import Polling from '@/component/Polling'

class Livewire {
    constructor(options = {}) {
        const defaults = {
            driver: 'http'
        }

        options = Object.assign({}, defaults, options);

        const driver = typeof options.driver === 'object'
            ? options.driver
            : drivers[options.driver]

        this.connection = new Connection(driver)
        this.components = componentStore
        this.onLoadCallback = () => {};

        this.activatePolyfills()

        this.components.initializeGarbageCollection()
    }

    find(componentId) {
        return this.components.componentsById[componentId]
    }

    hook(name, callback) {
        this.components.registerHook(name, callback)
    }

    onLoad(callback) {
        this.onLoadCallback = callback
    }

    activatePolyfills() {
        ArrayFlat()
        ArrayFrom()
        ArrayIncludes()
        ElementGetAttributeNames()
    }

    emit(event, ...params) {
        this.components.emit(event, ...params)
    }

    on(event, callback) {
        this.components.on(event, callback)
    }

    restart() {
        this.stop()
        this.start()
    }

    stop() {
        this.components.tearDownComponents()
    }

    arrivalRequiresRefetch() {
        return window.performance && window.performance.getEntries()[0].type === 'back_forward'
        /* Firefox, Chrome, Edge, Opera, Firefox for Android, Chrome for Android, Android Browser, Opera Mobile
        Reference: https://developer.mozilla.org/en-US/docs/Web/API/PerformanceNavigationTiming/type
        Compatibility: https://developer.mozilla.org/en-US/docs/Web/API/PerformanceNavigationTiming/type#Browser_compatibility
        CanIUse: https://caniuse.com/#feat=mdn-api_performancenavigationtiming_type
        */
    }

    start() {
        /* Likely not the right place for this, just showing as example.  */
        if (this.arrivalRequiresRefetch()) {
            /* The user has arrived through the browser's history traversal operation.
            So we can do new things to keep protected properties in Livewire for good. */
        }

        DOM.rootComponentElementsWithNoParents().forEach(el => {
            this.components.addComponent(
                new Component(el, this.connection)
            )
        })

        this.onLoadCallback()
        dispatch('livewire:load')

        // This is very important for garbage collecting components
        // on the backend.
        window.addEventListener('beforeunload', () => {
            this.components.tearDownComponents()
        })

        document.addEventListener('visibilitychange', () => {
            this.components.livewireIsInBackground = document.hidden
        }, false);
    }

    rescan() {
        DOM.rootComponentElementsWithNoParents().forEach(el => {
            const componentId = el.getAttribute('id')
            if (this.components.hasComponent(componentId)) return

            this.components.addComponent(
                new Component(el, this.connection)
            )
        })
    }

    beforeDomUpdate(callback) {
        this.components.beforeDomUpdate(callback)
    }

    afterDomUpdate(callback) {
        this.components.afterDomUpdate(callback)
    }

    plugin(callable) {
        callable(this)
    }
}

if (! window.Livewire) {
    window.Livewire = Livewire
}

LoadingStates()
DirtyStates()
OfflineStates()
Polling()

dispatch('livewire:available')

export default Livewire
