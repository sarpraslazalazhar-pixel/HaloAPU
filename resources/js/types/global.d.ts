import { Config, RouteParam, RouteParamsWithQueryOverload, RouteParams } from 'ziggy-js';

declare global {
    function route(): typeof import('ziggy-js').route;
    function route(name: string, params?: RouteParamsWithQueryOverload | RouteParam, absolute?: boolean, config?: Config): string;

    interface Window {
        Echo: any;
    }
}

declare module '*.css';
