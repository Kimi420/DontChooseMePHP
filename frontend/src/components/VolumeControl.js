import React from 'react';

function VolumeControl({ volume, onChange }) {
  return (
    <div style={{ marginBottom: '20px' }}>
      <label htmlFor="volume">🔊 Lautstärke:&nbsp;</label>
      <input
        id="volume"
        type="range"
        min={0}
        max={1}
        step={0.01}
        value={volume}
        onChange={e => onChange(Number(e.target.value))}
        style={{ width: '200px' }}
      />
      <span style={{ marginLeft: '10px' }}>{Math.round(volume * 100)}%</span>
    </div>
  );
}

export default VolumeControl;

