# Monitoring

MATA Dashboard collects monitoring data from configured MATA Node endpoints and shows the current results in the dashboard. Scheduled jobs refresh that data and record events for monitored conditions. Alarm delivery is configured separately from event recording.

Configure monitored servers in [`servers.json`](configuration.md). Run the dashboard and its scheduled jobs together so collected data and monitoring checks remain current.

## Application monitoring

Application error and log-size checks run from the scheduled server-data fetch. See [Application Error Monitoring](application-error-monitoring.md) for configuration and behavior.
