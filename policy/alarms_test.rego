package alarms_test

import rego.v1
import data.alarms

test_fatal_errors_occurring_in_prod_is_allowed if {
    decision := alarms.decision with input as {
        "event": {"type": "fatal_errors_occurring", "counts": {"1h": 2}},
        "application": {"env": "prod"},
    }

    decision.allow == true
    decision.reason == "fatal_errors_prod"
}

test_fatal_errors_occurring_in_dev_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "fatal_errors_occurring", "counts": {"1h": 2}},
        "application": {"env": "dev"},
    }

    decision.allow == false
    decision.reason == "no_rule_match"
}

test_fatal_errors_occurring_with_zero_count_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "fatal_errors_occurring", "counts": {"1h": 0}},
        "application": {"env": "prod"},
    }

    decision.allow == false
}

test_fatal_errors_occurring_without_counts_is_denied if {
    decision := alarms.decision with input as {
        "event": {"type": "fatal_errors_occurring"},
        "application": {"env": "prod"},
    }

    decision.allow == false
}
