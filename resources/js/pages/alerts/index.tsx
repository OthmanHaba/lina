import { FC } from 'react'

interface AlertsIndexProps {
    alerts: any[]
    channels: any[]
    servers: any[]
    sources: string[]
}

const AlertsIndex: FC<AlertsIndexProps> = ({ alerts, channels, servers, sources }) => {
    return (
        <div>
            <h1>Alerts</h1>
        </div>
    )
}

export default AlertsIndex
