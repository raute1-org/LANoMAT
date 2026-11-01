//! Small pure helpers to adapt device formats to the session format: channel downmix and a
//! pull-style linear resampler. Linear interpolation is adequate for speech at the rates devices
//! use (44.1/48/96 kHz); a high-quality polyphase resampler is a possible later improvement.

use std::collections::VecDeque;

/// Average of the channels of every interleaved frame.
pub fn downmix_to_mono(interleaved: &[f32], channels: usize) -> Vec<f32> {
    if channels <= 1 {
        return interleaved.to_vec();
    }
    interleaved
        .chunks_exact(channels)
        .map(|f| f.iter().sum::<f32>() / channels as f32)
        .collect()
}

pub fn f32_to_i16(s: f32) -> i16 {
    (s.clamp(-1.0, 1.0) * f32::from(i16::MAX)) as i16
}

pub fn i16_to_f32(s: i16) -> f32 {
    f32::from(s) / f32::from(i16::MAX)
}

/// Linear resampler that pulls its input from a queue, one output sample at a time. Works for
/// both directions (device to 48 kHz, 48 kHz to device) and keeps its phase across calls.
#[derive(Debug, Clone)]
pub struct PullResampler {
    step: f64,
    pos: f64,
}

impl PullResampler {
    pub fn new(in_rate: u32, out_rate: u32) -> Self {
        Self {
            step: f64::from(in_rate) / f64::from(out_rate),
            pos: 0.0,
        }
    }

    /// Next output sample, or `None` when the queue does not yet hold enough input.
    pub fn pull(&mut self, src: &mut VecDeque<f32>) -> Option<f32> {
        let i = self.pos.floor() as usize;
        let (a, b) = (*src.get(i)?, *src.get(i + 1)?);
        let frac = (self.pos - i as f64) as f32;
        let value = a + (b - a) * frac;
        self.pos += self.step;
        let drop = (self.pos.floor() as usize).min(src.len());
        src.drain(..drop);
        self.pos -= drop as f64;
        Some(value)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn downmix_averages_channels() {
        assert_eq!(downmix_to_mono(&[1.0, 0.0, 0.5, 0.5], 2), vec![0.5, 0.5]);
        assert_eq!(downmix_to_mono(&[0.1, 0.2], 1), vec![0.1, 0.2]);
    }

    #[test]
    fn sample_conversion_clamps_and_round_trips() {
        assert_eq!(f32_to_i16(2.0), i16::MAX);
        assert_eq!(f32_to_i16(-2.0), -i16::MAX);
        assert_eq!(f32_to_i16(0.0), 0);
        assert!((i16_to_f32(f32_to_i16(0.5)) - 0.5).abs() < 1e-3);
    }

    fn drain(r: &mut PullResampler, q: &mut VecDeque<f32>) -> Vec<f32> {
        std::iter::from_fn(|| r.pull(q)).collect()
    }

    #[test]
    fn same_rate_is_a_passthrough_except_the_last_sample() {
        let mut r = PullResampler::new(48_000, 48_000);
        let mut q: VecDeque<f32> = (0..10).map(|v| v as f32).collect();
        let out = drain(&mut r, &mut q);
        assert_eq!(out, (0..9).map(|v| v as f32).collect::<Vec<_>>());
    }

    #[test]
    fn halving_the_rate_takes_every_second_sample() {
        let mut r = PullResampler::new(96_000, 48_000);
        let mut q: VecDeque<f32> = (0..11).map(|v| v as f32).collect();
        let out = drain(&mut r, &mut q);
        assert_eq!(out, vec![0.0, 2.0, 4.0, 6.0, 8.0]);
    }

    #[test]
    fn upsampling_interpolates_linearly() {
        let mut r = PullResampler::new(24_000, 48_000);
        let mut q: VecDeque<f32> = vec![0.0, 1.0, 2.0].into();
        let out = drain(&mut r, &mut q);
        assert_eq!(out, vec![0.0, 0.5, 1.0, 1.5]);
    }

    #[test]
    fn output_length_tracks_the_rate_ratio_and_keeps_phase_across_calls() {
        let mut r = PullResampler::new(44_100, 48_000);
        let mut q = VecDeque::new();
        let mut produced = 0;
        for chunk in 0..100 {
            q.extend((0..441).map(|v| (chunk * 441 + v) as f32));
            produced += drain(&mut r, &mut q).len();
        }
        // 44100 input samples -> ~48000 output samples.
        assert!((47_990..=48_000).contains(&produced), "{produced}");
    }
}
