// Shared type definitions for the task-workflow extension

export const STATUSES = [
	"TODO",
	"IN-PROGRESS",
	"CODE-REVIEW",
	"DONE",
	"ARCHIVE",
	"CANCELLED",
] as const;
export type TaskStatus = (typeof STATUSES)[number];

/** Default task_list statuses, omitting CANCELLED and ARCHIVE unless requested. */
export const DEFAULT_LISTED_STATUSES = [
	"TODO",
	"IN-PROGRESS",
	"CODE-REVIEW",
	"DONE",
] as const satisfies readonly TaskStatus[];

export type ExecResult = {
	stdout: string;
	stderr: string;
	code: number;
	killed?: boolean;
};

export type TaskInfo = {
	status: TaskStatus;
	file: string;
	path: string;
	title: string;
	branch?: string;
	worktree?: string;
	prUrl?: string;
};

export type WorktreeCreateResult = {
	branch: string;
	worktree: string;
	output: string;
	veraCopied: boolean;
	vendorCopied: boolean;
	extensionsVendorInstalled: boolean;
	/** Non-fatal composer install diagnostic when install was attempted but failed. */
	extensionsVendorNote?: string;
	ideaExclusionsUpdated: boolean;
	ideaNote?: string;
	/** Note from minimal per-worktree .idea creation (success or skip/degrade). */
	ideaSetupNote?: string;
	/** Note from ide_open_project after worktree setup (success or degrade). */
	ideOpenNote?: string;
};
