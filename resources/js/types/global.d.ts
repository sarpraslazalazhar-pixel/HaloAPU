import { Config, RouteParam, RouteParamsWithQueryOverload } from 'ziggy-js';

// Structural types for the Echo client created in bootstrap.js.
// Handler parameters are `never` so a caller can pass any concrete payload type:
// every function accepts `never`, which keeps listener bodies fully typed without
// `any` or assertions at each call site.
export interface EchoChannel {
  listen(event: string, handler: (payload: never) => void): EchoChannel;
  stopListening(event: string, handler: (payload: never) => void): EchoChannel;
  notification(handler: (payload: never) => void): EchoChannel;
  error(handler: (payload: never) => void): EchoChannel;
  subscribed(handler: () => void): EchoChannel;
  here(handler: (members: never) => void): EchoChannel;
  joining(handler: (member: never) => void): EchoChannel;
  leaving(handler: (member: never) => void): EchoChannel;
}

export interface EchoClient {
  private(name: string): EchoChannel;
  join(name: string): EchoChannel;
  leave(name: string): void;
  socketId(): string | undefined;
  disconnect(): void;
}

/** Public realtime settings rendered by the server into `window.__realtime`. */
export interface RealtimeConfig {
  broadcaster: 'pusher' | 'reverb';
  key: string;
  cluster?: string;
  host?: string;
  port?: number;
  scheme?: string;
}

declare global {
  function route(): typeof import('ziggy-js').route;
  function route(name: string, params?: RouteParamsWithQueryOverload | RouteParam, absolute?: boolean, config?: Config): string;

  interface Window {
    Echo: EchoClient | null;
    Pusher: unknown;
    __realtime?: RealtimeConfig | null;
  }
}

declare module '*.css';
