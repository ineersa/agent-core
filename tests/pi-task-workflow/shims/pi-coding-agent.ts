export type ExtensionAPI = {
	exec: (
		command: string,
		args: string[],
		options: { cwd: string; signal?: AbortSignal; timeout?: number },
	) => Promise<{ stdout: string; stderr: string; code: number; killed?: boolean }>;
};
