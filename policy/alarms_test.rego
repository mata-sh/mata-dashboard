package alarms_test

import rego.v1
import data.alarms

test_fatal_errors_occurring_in_prod_is_allowed_without_counts if {
    decision := alarms.decision with input as {
        "event": {"type": "fatal_errors_occurring"},
        "application": {"env": "prod"},
    }

    decision.allow == true
    decision.priority == 10
    decision.reason == "fatal_errors_prod"
}

test_fatal_errors_occurring_in_non_prod_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "fatal_errors_occurring"},
        "application": {"env": "dev"},
    }

    decision.allow == false
    decision.reason == "no_rule_match"
}

test_monitored_critical_is_allowed if {
    decision := alarms.decision with input as {
        "event": {"type": "cpu_usage_too_high", "level": "critical"},
    }

    decision.allow == true
    decision.priority == 10
    decision.reason == "monitored_critical"
}

test_monitored_warning_is_allowed if {
    decision := alarms.decision with input as {
        "event": {"type": "app_unreachable", "level": "warning"},
    }

    decision.allow == true
    decision.priority == 60
    decision.reason == "monitored_warning"
}

test_monitored_info_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "filecount_too_large", "level": "info"},
    }

    decision.allow == false
    decision.reason == "no_rule_match"
}

test_unknown_critical_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "unknown_type", "level": "critical"},
    }

    decision.allow == false
    decision.reason == "no_rule_match"
}

test_node_unreachable_critical_is_allowed if {
    decision := alarms.decision with input as {
        "event": {"type": "node_unreachable", "level": "critical"},
    }

    decision.allow == true
    decision.priority == 10
    decision.reason == "monitored_critical"
}

test_server_metrics_ingestion_info_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "server_metrics.ingestion", "level": "info"},
    }

    decision.allow == false
    decision.reason == "no_rule_match"
}

test_orders_export_stale_critical_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "orders_export_stale", "level": "critical"},
    }

    decision.allow == false
    decision.reason == "no_rule_match"
}
