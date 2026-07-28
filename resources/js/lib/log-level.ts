// ponytail: heuristic only — streams carry no level label; this colors rows, nothing more.
export function detectLevel(line: string): 'error' | 'warn' | null {
    if (/\b(error|err|fatal|critical|exception|panic)\b/i.test(line)) {
        return 'error';
    }

    if (/\b(warn|warning)\b/i.test(line)) {
        return 'warn';
    }

    return null;
}
