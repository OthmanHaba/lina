import { Head } from '@inertiajs/react';

interface Server {
    id: number;
    name: string;
}

interface Props {
    servers: Server[];
    sources: string[];
}

export default function LogsIndex({ servers, sources }: Props) {
    return (
        <>
            <Head title="Logs" />
            <div>
                <h1>Logs</h1>
                {/* Log explorer UI will be implemented here */}
            </div>
        </>
    );
}
