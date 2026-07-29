<?php

namespace App\Services;

use App\Enums\LogSourceType;
use App\Models\LogSource;
use App\Models\Server;

class AlloyConfigRenderer
{
    /**
     * Render the complete config.alloy for a server. All labels are set here —
     * agents can never invent labels the dashboard didn't define.
     */
    public function render(Server $server): string
    {
        $pushUrl = rtrim(config('app.url'), '/').'/api/agent/push';

        $blocks = [
            <<<ALLOY
            // Managed by Lina — local edits are overwritten on the next config poll.

            loki.write "lina" {
              endpoint {
                url = "{$pushUrl}"
                bearer_token = sys.env("LINA_TOKEN")
              }
              external_labels = {
                server_id = "{$server->id}",
              }
            }
            ALLOY,
        ];

        foreach ($server->logSources()->where('enabled', true)->get() as $source) {
            $blocks[] = match ($source->type) {
                LogSourceType::Systemd => $this->systemdBlock($source),
                LogSourceType::Docker => $this->dockerBlock($source),
                LogSourceType::Nginx => $this->fileBlock($source, [
                    $source->config['access_log'] ?? '/var/log/nginx/access.log',
                    $source->config['error_log'] ?? '/var/log/nginx/error.log',
                ]),
                LogSourceType::File => $this->fileBlock($source, $source->config['paths'] ?? []),
                LogSourceType::Container => $this->fileBlock($source, ['/var/log/lina/'.$source->id.'/*.log']),
            };
        }

        return implode("\n\n", $blocks)."\n";
    }

    protected function systemdBlock(LogSource $source): string
    {
        $id = 'src_'.$source->id;
        $name = $this->quote($source->name);
        $units = array_values(array_filter($source->config['units'] ?? []));

        $keepRule = '';
        if ($units !== []) {
            $regex = $this->quote(implode('|', array_map(fn ($unit) => preg_quote($unit), $units)));
            $keepRule = <<<ALLOY

              rule {
                source_labels = ["__journal__systemd_unit"]
                regex = "{$regex}"
                action = "keep"
              }
            ALLOY;
        }

        return <<<ALLOY
        loki.relabel "{$id}" {
          forward_to = []
        {$keepRule}
          rule {
            source_labels = ["__journal__systemd_unit"]
            target_label = "unit"
          }
        }

        loki.source.journal "{$id}" {
          relabel_rules = loki.relabel.{$id}.rules
          labels = { source = "{$name}" }
          forward_to = [loki.write.lina.receiver]
        }
        ALLOY;
    }

    /** @param array<int, string> $paths */
    protected function fileBlock(LogSource $source, array $paths): string
    {
        $id = 'src_'.$source->id;
        $name = $this->quote($source->name);

        $targets = implode("\n", array_map(
            fn ($path) => '    { __path__ = "'.$this->quote($path).'", source = "'.$name.'" },',
            $paths,
        ));

        return <<<ALLOY
        local.file_match "{$id}" {
          path_targets = [
        {$targets}
          ]
        }

        loki.source.file "{$id}" {
          targets = local.file_match.{$id}.targets
          forward_to = [loki.write.lina.receiver]
        }
        ALLOY;
    }

    protected function dockerBlock(LogSource $source): string
    {
        $id = 'src_'.$source->id;
        $name = $this->quote($source->name);
        $containers = array_values(array_filter($source->config['containers'] ?? []));

        $keepRule = '';
        if ($containers !== []) {
            $regex = $this->quote('/?('.implode('|', array_map(fn ($container) => preg_quote($container), $containers)).')');
            $keepRule = <<<ALLOY

              rule {
                source_labels = ["__meta_docker_container_name"]
                regex = "{$regex}"
                action = "keep"
              }
            ALLOY;
        }

        return <<<ALLOY
        discovery.docker "{$id}" {
          host = "unix:///var/run/docker.sock"
        }

        discovery.relabel "{$id}" {
          targets = discovery.docker.{$id}.targets
        {$keepRule}
          rule {
            source_labels = ["__meta_docker_container_name"]
            regex = "/?(.*)"
            target_label = "container"
          }
          rule {
            target_label = "source"
            replacement = "{$name}"
          }
        }

        loki.source.docker "{$id}" {
          host = "unix:///var/run/docker.sock"
          targets = discovery.relabel.{$id}.output
          forward_to = [loki.write.lina.receiver]
        }
        ALLOY;
    }

    /**
     * Escape a value for inclusion inside a double-quoted Alloy string.
     */
    protected function quote(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }
}
