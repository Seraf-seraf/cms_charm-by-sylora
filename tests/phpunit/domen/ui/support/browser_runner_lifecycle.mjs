import { spawn } from 'node:child_process';

const scenario = process.argv[3];

if (scenario === 'fast') {
	process.stdout.write('{"ok":true}\n');
} else if (scenario === 'success') {
	process.stdout.write(`${JSON.stringify({ ok: true, payload: 'x'.repeat(128 * 1024) })}\n`);
	process.stderr.write('diagnostic '.repeat(12 * 1024));
} else if (scenario === 'timeout') {
	const markerPath = process.env.SYLORA_BROWSER_TIMEOUT_MARKER;

	if (!markerPath) throw new Error('Timeout test marker is missing.');

	const childProgram = `setTimeout(() => require('node:fs').writeFileSync(${JSON.stringify(markerPath)}, 'survived'), 3500);`;
	const child = spawn(process.execPath, ['-e', childProgram], { stdio: 'ignore' });
	process.stderr.write(`spawned-child=${child.pid}\n`);
	setInterval(() => {}, 1000);
} else {
	throw new Error(`Unknown lifecycle scenario: ${scenario}`);
}
