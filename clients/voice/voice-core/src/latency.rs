//! Pure latency maths. No I/O, no clocks: every instant is a `Duration` since one shared origin.
//!
//! This mirrors the browser self-test of the web voice runbook ("Measuring and tuning latency"):
//! a click onset is scheduled at the source, the receiving side detects the first sample above a
//! threshold, and the delay is `detected - scheduled` on **one clock** (here: one process, one
//! `Instant` origin), so no clock synchronisation is needed. The result covers encode, network,
//! SFU, jitter buffer and decode, and excludes the microphone and speaker buffers of the OS.

use std::time::Duration;

/// Summary statistics of a series of one-way delays.
#[derive(Debug, Clone, PartialEq)]
pub struct LatencyStats {
    pub count: usize,
    pub min: Duration,
    pub median: Duration,
    /// 95th percentile, nearest-rank method.
    pub p95: Duration,
    pub max: Duration,
    /// Standard deviation (population) of the delays, the runbook's "jitter".
    pub jitter: Duration,
    pub mean: Duration,
}

impl LatencyStats {
    /// `None` for an empty series.
    pub fn from_delays(delays: &[Duration]) -> Option<Self> {
        if delays.is_empty() {
            return None;
        }
        let mut sorted: Vec<f64> = delays.iter().map(Duration::as_secs_f64).collect();
        sorted.sort_by(f64::total_cmp);
        let n = sorted.len();
        let mean = sorted.iter().sum::<f64>() / n as f64;
        let variance = sorted.iter().map(|d| (d - mean).powi(2)).sum::<f64>() / n as f64;
        let median = if n % 2 == 1 {
            sorted[n / 2]
        } else {
            (sorted[n / 2 - 1] + sorted[n / 2]) / 2.0
        };
        Some(Self {
            count: n,
            min: Duration::from_secs_f64(sorted[0]),
            median: Duration::from_secs_f64(median),
            p95: Duration::from_secs_f64(nearest_rank(&sorted, 0.95)),
            max: Duration::from_secs_f64(sorted[n - 1]),
            jitter: Duration::from_secs_f64(variance.sqrt()),
            mean: Duration::from_secs_f64(mean),
        })
    }

    /// Multi-line, human readable report (milliseconds with one decimal).
    pub fn report(&self) -> String {
        format!(
            "samples  {}\nmin      {}\nmedian   {}\np95      {}\nmax      {}\nmean     {}\njitter   {} (std dev)",
            self.count,
            ms(self.min),
            ms(self.median),
            ms(self.p95),
            ms(self.max),
            ms(self.mean),
            ms(self.jitter),
        )
    }
}

fn ms(d: Duration) -> String {
    format!("{:.1} ms", d.as_secs_f64() * 1000.0)
}

/// Nearest-rank percentile of a sorted, non-empty slice: the value at rank `ceil(p * n)`.
fn nearest_rank(sorted: &[f64], p: f64) -> f64 {
    let n = sorted.len();
    let rank = (p * n as f64).ceil() as usize;
    sorted[rank.clamp(1, n) - 1]
}

/// Pairs every scheduled onset with the first detected onset that follows it and precedes the next
/// scheduled onset (so a missed burst never shifts all later pairs), within `max_delay`.
/// Returns the delays of the matched pairs, in scheduled order. Both inputs must be ascending.
pub fn match_onsets(
    sent: &[Duration],
    detected: &[Duration],
    max_delay: Duration,
) -> Vec<Duration> {
    let mut out = Vec::new();
    let mut di = 0;
    for (i, &s) in sent.iter().enumerate() {
        let next = sent.get(i + 1).copied();
        // Skip detections that belong to an earlier burst (before this onset).
        while di < detected.len() && detected[di] < s {
            di += 1;
        }
        if let Some(&d) = detected.get(di) {
            let before_next = next.is_none_or(|n| d < n);
            if before_next && d - s <= max_delay {
                out.push(d - s);
                di += 1;
            }
        }
    }
    out
}

/// Detects click onsets in a stream of fixed-rate mono frames: the first sample whose magnitude
/// reaches `threshold`, then silence for `hold_off` before the next onset can fire.
#[derive(Debug, Clone)]
pub struct OnsetDetector {
    threshold: i16,
    sample_rate: u32,
    hold_off: Duration,
    /// Earliest instant (stream timeline) at which a new onset may fire.
    armed_at: Duration,
}

impl OnsetDetector {
    pub fn new(threshold: i16, sample_rate: u32, hold_off: Duration) -> Self {
        Self {
            threshold,
            sample_rate,
            hold_off,
            armed_at: Duration::ZERO,
        }
    }

    /// Feeds one frame whose first sample sits at `frame_start`; returns the onset instant
    /// (`frame_start + index / sample_rate`) when one is found in this frame.
    pub fn feed(&mut self, samples: &[i16], frame_start: Duration) -> Option<Duration> {
        for (i, &s) in samples.iter().enumerate() {
            let at = frame_start + Duration::from_secs_f64(i as f64 / f64::from(self.sample_rate));
            if at < self.armed_at {
                continue;
            }
            if s.unsigned_abs() >= self.threshold.unsigned_abs() {
                self.armed_at = at + self.hold_off;
                return Some(at);
            }
        }
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn ms_(v: f64) -> Duration {
        Duration::from_secs_f64(v / 1000.0)
    }

    #[test]
    fn empty_series_has_no_stats() {
        assert_eq!(LatencyStats::from_delays(&[]), None);
    }

    #[test]
    fn single_sample() {
        let s = LatencyStats::from_delays(&[ms_(30.0)]).unwrap();
        assert_eq!(s.count, 1);
        assert_eq!(s.min, s.max);
        assert_eq!(s.median, ms_(30.0));
        assert_eq!(s.p95, ms_(30.0));
        assert_eq!(s.jitter, Duration::ZERO);
    }

    #[test]
    fn stats_of_a_known_series() {
        // 1..=20 ms: median 10.5, p95 = rank ceil(19) = 19 ms, std dev of 1..20 = sqrt(33.25).
        let delays: Vec<Duration> = (1..=20).map(|v| ms_(f64::from(v))).collect();
        let s = LatencyStats::from_delays(&delays).unwrap();
        assert_eq!(s.count, 20);
        assert_eq!(s.min, ms_(1.0));
        assert_eq!(s.max, ms_(20.0));
        assert!((s.median.as_secs_f64() - 0.0105).abs() < 1e-9);
        assert!((s.p95.as_secs_f64() - 0.019).abs() < 1e-9);
        assert!((s.jitter.as_secs_f64() - 33.25_f64.sqrt() / 1000.0).abs() < 1e-9);
        assert!((s.mean.as_secs_f64() - 0.0105).abs() < 1e-9);
    }

    #[test]
    fn unsorted_input_is_handled() {
        let s = LatencyStats::from_delays(&[ms_(50.0), ms_(10.0), ms_(30.0)]).unwrap();
        assert_eq!(s.min, ms_(10.0));
        assert_eq!(s.median, ms_(30.0));
        assert_eq!(s.max, ms_(50.0));
    }

    #[test]
    fn report_mentions_every_figure() {
        let r = LatencyStats::from_delays(&[ms_(12.0), ms_(14.0)])
            .unwrap()
            .report();
        for key in ["min", "median", "p95", "max", "jitter"] {
            assert!(r.contains(key), "{r}");
        }
        assert!(r.contains("12.0 ms"));
    }

    #[test]
    fn matching_pairs_each_onset_with_the_next_detection() {
        let sent = [ms_(1000.0), ms_(2000.0), ms_(3000.0)];
        let detected = [ms_(1030.0), ms_(2045.0), ms_(3020.0)];
        let d = match_onsets(&sent, &detected, ms_(500.0));
        assert_eq!(d, vec![ms_(30.0), ms_(45.0), ms_(20.0)]);
    }

    #[test]
    fn a_lost_burst_does_not_shift_later_pairs() {
        let sent = [ms_(1000.0), ms_(2000.0), ms_(3000.0)];
        // The second burst was never detected.
        let detected = [ms_(1030.0), ms_(3020.0)];
        let d = match_onsets(&sent, &detected, ms_(500.0));
        assert_eq!(d, vec![ms_(30.0), ms_(20.0)]);
    }

    #[test]
    fn a_late_detection_beyond_the_window_is_dropped() {
        let sent = [ms_(1000.0)];
        let detected = [ms_(1900.0)];
        assert!(match_onsets(&sent, &detected, ms_(500.0)).is_empty());
    }

    #[test]
    fn detector_finds_first_sample_over_threshold() {
        let mut det = OnsetDetector::new(1000, 48_000, ms_(500.0));
        let mut frame = vec![0_i16; 480];
        assert_eq!(det.feed(&frame, Duration::ZERO), None);
        frame[48] = 20_000; // 1 ms into the frame
        let at = det.feed(&frame, ms_(10.0)).unwrap();
        assert!((at.as_secs_f64() - 0.011).abs() < 1e-6, "{at:?}");
    }

    #[test]
    fn detector_holds_off_after_a_hit() {
        let mut det = OnsetDetector::new(1000, 48_000, ms_(500.0));
        let loud = vec![20_000_i16; 480];
        assert!(det.feed(&loud, Duration::ZERO).is_some());
        // The rest of the same burst and the following frames must not fire again.
        assert!(det.feed(&loud, ms_(10.0)).is_none());
        assert!(det.feed(&loud, ms_(400.0)).is_none());
        // After the hold-off a new burst fires again.
        assert!(det.feed(&loud, ms_(600.0)).is_some());
    }

    #[test]
    fn negative_samples_count_too() {
        let mut det = OnsetDetector::new(1000, 48_000, ms_(100.0));
        let mut frame = vec![0_i16; 480];
        frame[0] = i16::MIN;
        assert_eq!(det.feed(&frame, ms_(5.0)), Some(ms_(5.0)));
    }
}
