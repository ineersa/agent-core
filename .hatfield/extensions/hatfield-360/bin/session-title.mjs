try {
    const [sessionId, dir] = process.argv.slice(2);
    const { getSessionInfo } = await import('@anthropic-ai/claude-agent-sdk');
    const info = await getSessionInfo(sessionId, { dir });
    const title = info?.customTitle;
    if (info?.sessionId === sessionId && typeof title === 'string' && title.trim() && [...title].length <= 1024) {
        process.stdout.write(JSON.stringify(title));
    } else {
        process.exitCode = 1;
    }
} catch {
    // The caller reports a fixed diagnostic rather than SDK errors or session data.
    process.exitCode = 1;
}
