/// <reference types="astro/client" />

interface ImportMetaEnv {
	readonly PUBLIC_FORMSTEAD_ENDPOINT: string;
	readonly PUBLIC_FORMSTEAD_CLOSED_ENDPOINT: string;
	readonly PUBLIC_FORMSTEAD_RATE_ENDPOINT: string;
	readonly PUBLIC_FORMSTEAD_SAME_ENDPOINT: string;
}

interface ImportMeta {
	readonly env: ImportMetaEnv;
}
