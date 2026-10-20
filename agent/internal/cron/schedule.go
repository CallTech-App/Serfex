package cron

import (
	"strconv"
	"strings"
)

// field is one of the five fields of a cron schedule.
type field struct {
	name     string
	min, max int
	// names are the three-letter names the field accepts, from min.
	names []string
}

var fields = []field{
	{name: "minute", min: 0, max: 59},
	{name: "hour", min: 0, max: 23},
	{name: "day of month", min: 1, max: 31},
	{name: "month", min: 1, max: 12, names: []string{"jan", "feb", "mar", "apr", "may", "jun", "jul", "aug", "sep", "oct", "nov", "dec"}},
	// 0 and 7 are both Sunday.
	{name: "day of week", min: 0, max: 7, names: []string{"sun", "mon", "tue", "wed", "thu", "fri", "sat"}},
}

// NormalizeSchedule checks a five-field cron schedule ("*/5 * * * *",
// "30 3 * * mon-fri") and returns it with single spaces and lowercase
// names. Each field is a comma-separated list of *, a number or name,
// a range a-b, or a step */n or a-b/n.
func NormalizeSchedule(s string) (string, error) {
	parts := strings.Fields(strings.ToLower(s))
	if len(parts) != len(fields) {
		return "", invalid("schedule %q must have 5 fields: minute, hour, day of month, month and day of week", s)
	}
	for i, p := range parts {
		if err := fields[i].check(p); err != nil {
			return "", invalid("schedule %q: %s", s, err.Error())
		}
	}
	return strings.Join(parts, " "), nil
}

func (f field) check(s string) error {
	if s == "" || len(s) > 100 {
		return invalid("the %s field is not valid", f.name)
	}
	for _, item := range strings.Split(s, ",") {
		if err := f.item(item); err != nil {
			return err
		}
	}
	return nil
}

func (f field) item(s string) error {
	rng, step, hasStep := strings.Cut(s, "/")
	if hasStep {
		n, _ := strconv.Atoi(step)
		if !digits(step, 2) || n < 1 || n > f.max {
			return invalid("the step %q of the %s field must be a number from 1 to %d", step, f.name, f.max)
		}
	}
	if rng == "*" {
		return nil
	}
	lo, hi, isRange := strings.Cut(rng, "-")
	if hasStep && !isRange {
		return invalid("in the %s field a step needs * or a range before it (*/n or a-b/n), not %q", f.name, s)
	}
	a, err := f.value(lo)
	if err != nil {
		return err
	}
	if !isRange {
		return nil
	}
	b, err := f.value(hi)
	if err != nil {
		return err
	}
	if a > b {
		return invalid("the range %q of the %s field runs backwards", rng, f.name)
	}
	return nil
}

func (f field) value(s string) (int, error) {
	for i, n := range f.names {
		if s == n {
			return f.min + i, nil
		}
	}
	if !digits(s, 2) {
		return 0, invalid("%q is not a valid %s", s, f.name)
	}
	n, _ := strconv.Atoi(s)
	if n < f.min || n > f.max {
		return 0, invalid("the %s must be from %d to %d, not %s", f.name, f.min, f.max, s)
	}
	return n, nil
}

// digits reports whether s is 1 to n decimal digits.
func digits(s string, n int) bool {
	return s != "" && len(s) <= n && strings.Trim(s, "0123456789") == ""
}
